<?php
$xml = new DOMDocument();
$cCode = escapeshellarg(<<<CCODE
typedef void jmp_buf;
#include "php.h"
#include "zend_language_scanner.h"
#include "zend_hash.h"
#include "zend_inheritance.h"
// zend_closures.c - why isn't this in a header file???
typedef struct _zend_closure {
	zend_object       std;
	zend_function     func;
	zval              this_ptr;
	zend_class_entry *called_scope;
	zif_handler       orig_internal_handler;
} zend_closure;
CCODE);
$xml->loadXML(`echo $cCode|cpp $(php-config --includes) -P -C -D"__attribute__(ARGS)=" - |srcml --language C`);
// Remove all comments
/**
 * @var DOMNode $comment
 */
foreach(iterator_to_array($xml->getElementsByTagName("comment")) as $comment) {
    /**
     * @var DOMNode $parent
     */
    $parent = $comment->parentNode;
    $parent->removeChild($comment);
}

$version = PHP_MAJOR_VERSION."_" . PHP_MINOR_VERSION . "_" . (PHP_INT_SIZE === 8 ? "x64" : "x32") . "_" . (ZEND_THREAD_SAFE ? "zts" : "nts");

$symbols = [];

/**
 * @var DOMElement $typedef
 */
foreach(iterator_to_array($xml->getElementsByTagName("typedef")) as $typedef) {
    /**
     * @var list<DOMElement> $children
     */
    $children = array_values(array_filter(iterator_to_array($typedef->childNodes), fn(DOMNode $dn): bool => $dn instanceof DOMElement));
    if(count($children) === 2 && $children[0]->localName === "type" && $children[1]->localName === "name") {
        // Simple typedef: <typedef>typedef <type>...</type> <name>zend_uchar</name>;</typedef>
        // Also handles typedef union
        $symbols[$children[1]->textContent] = $typedef->textContent;
    }
    if(count($children) === 1 && $children[0]->localName === "function_decl") {
        // Function declaration typedef: <typedef>typedef <function_decl><type>...</type>(<modifier>*</modifier>)<name>compare_func_t</name>...</function_decl></typedef>
        $nameNode = array_values(array_filter(iterator_to_array($children[0]->childNodes), fn(DOMNode $dn) => $dn instanceof DOMElement && $dn->localName === "name"))[0];
        if($nameNode instanceof DOMElement) {
            $symbols[$nameNode->textContent] = $typedef->textContent;
        }
    }
}
/**
 * @var DOMElement $struct
 */
foreach([...iterator_to_array($xml->getElementsByTagName("struct_decl")), ...iterator_to_array($xml->getElementsByTagName("struct"))] as $struct) {

    /**
     * @var list<DOMElement> $children
     */
    $children = array_values(array_filter(iterator_to_array($struct->childNodes), fn(DOMNode $dn): bool => $dn instanceof DOMElement));
    if($children[0]->localName === "name") {
        $symbols[$children[0]->textContent] = $struct->textContent;
    }
}
/**
 * @var DOMElement $union
 */
foreach(iterator_to_array($xml->getElementsByTagName("union")) as $union) {

    /**
     * @var list<DOMElement> $children
     */
    $children = array_values(array_filter(iterator_to_array($union->childNodes), fn(DOMNode $dn): bool => $dn instanceof DOMElement));
    if($children[0]->localName === "name") {
        $symbols[$children[0]->textContent] = $union->textContent;
    }
}
/**
 * @var DOMElement $declare
 */
foreach(iterator_to_array($xml->getElementsByTagName("decl_stmt")) as $declare) {
    // <decl_stmt><decl><type>...</type> <name>std_object_handlers</name></decl>;</decl_stmt>

    /**
     * @var list<DOMElement> $children
     */
    $children = array_values(array_filter(iterator_to_array($declare->childNodes), fn(DOMNode $dn): bool => $dn instanceof DOMElement));
    if(count($children) === 1 && $children[0]->localName === "decl") {
        $children = array_values(array_filter(iterator_to_array($children[0]->childNodes), fn(DOMNode $dn): bool => $dn instanceof DOMElement));
        if(count($children) === 2 && $children[0]->localName === "type" && $children[1]->localName === "name") {
            $symbols[$children[1]->textContent] = $declare->textContent;
        }
    }
}


/**
 * @var DOMElement $struct
 */
foreach(iterator_to_array($xml->getElementsByTagName("function_decl")) as $func) {
    // <function_decl><type>...</type> <name>std_object_handlers</name>;</decl_stmt>

    /**
     * @var list<DOMElement> $children
     */
    $children = array_values(array_filter(iterator_to_array($func->childNodes), fn(DOMNode $dn): bool => $dn instanceof DOMElement));
    if($children[0]->localName === "type" && $children[1]->localName === "name") {
        $symbols[$children[1]->textContent] = $func->textContent;
    }
}

// Remove duplicate newlines
$symbols = array_map(fn(string $s) => preg_replace('/^[ \t]*[\r\n]+/m', '', $s), $symbols);

$fname = __DIR__ . "/engine_$version.h";
$f = fopen($fname, "w");

fwrite($f, "#define FFI_SCOPE \"ZEngine\"\n#define FFI_LIB \"ZEND_LIBRARY_NAME\"\n");
foreach(file(__DIR__ . "/symbols_$version.txt") as $desired_symbol) {
    $desired_symbol = trim($desired_symbol);
    if(empty($desired_symbol)) continue;
    $symbol = $symbols[$desired_symbol] ?? null;
    if(is_null($symbol)) {
        throw new RuntimeException("Could not find symbol $desired_symbol");
    }
    fwrite($f, "$symbol\n");
}
fclose($f);

FFI::cdef(file_get_contents($fname));

//echo json_encode($symbols, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
// Turn into headers again: srcml out.xml | grep .
//$xml->save(__DIR__ . "/out.xml");