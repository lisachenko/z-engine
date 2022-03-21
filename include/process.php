<?php
// No-nonsense error handler: forces crashes when we hit an error
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
$isWindows = ($argv[1] ?? null) === "Windows" || (PHP_OS_FAMILY === "Windows");
$version = PHP_MAJOR_VERSION . "-" . PHP_MINOR_VERSION . "-" . (PHP_INT_SIZE === 8 ? "x64" : "x32") . "-" . (ZEND_THREAD_SAFE ? "zts" : "nts") . "-" . strtolower($isWindows ? "Windows" : PHP_OS_FAMILY);

$windowsDef = $isWindows ? "#define ZEND_WIN32" : "";
$xml = new DOMDocument();
$definesDef = "";
$symbolsToExport = [];
foreach(file(__DIR__ . "/symbols.txt") as $line) {
    $line = trim($line);
    if(empty($line)) continue;
    if($line[0] === "/") continue;
    if($line[0] === "#") {
        $isDefine = true;
        $line = substr($line, 1);
    } else {
        $isDefine = false;
    }
    $modifiers = explode(" ", $line);
    $name = array_shift($modifiers);
    foreach($modifiers as $modifier) {
        foreach(["<=", ">=", "<", ">", "=", "!="] as $comparator) {
            if(substr($modifier, 0, strlen($comparator)) !== $comparator) continue;
            $versionToCompare = substr($modifier, strlen($comparator));
            if(!preg_match("/^[0-9]+\\.[0-9]+$/", $versionToCompare)) continue; // Try next comparator
            if(version_compare(PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION, $versionToCompare, $comparator)) {
                continue 2; // Success, go to next modifier
            } else {
                continue 3; // Fail, go to next line
            }
        }
        if($modifier === "zts") {
            if(ZEND_THREAD_SAFE) {
                continue; // Success, go to next modifier
            } else {
                continue 2; // Fail, go to next line
            }
        }
        if($modifier === "nts") {
            if(!ZEND_THREAD_SAFE) {
                continue; // Success, go to next modifier
            } else {
                continue 2; // Fail, go to next line
            }
        }
        throw new RuntimeException("Could not parse modifier $modifier");
    }
    if($isDefine) {
        $definesDef .= "_$name = $name,\n\t";
    } else {
        $symbolsToExport[] = $name;
    }
}

$cCode = escapeshellarg(<<<CCODE
$windowsDef
typedef void jmp_buf;
#include "php.h"
#include "zend_language_scanner.h"
#include "zend_hash.h"
#include "zend_inheritance.h"
#include "zend_ast.h"
// zend_closures.c - why isn't this in a header file???
typedef struct _zend_closure {
	zend_object       std;
	zend_function     func;
	zval              this_ptr;
	zend_class_entry *called_scope;
	zif_handler       orig_internal_handler;
} zend_closure;
enum __defines {
    $definesDef
};
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
$defines = [];
/**
 * @var DOMElement $enum
 */
foreach(iterator_to_array($xml->getElementsByTagName("enum")) as $enum) {
    // <enum><name></name></enum>

    /**
     * @var list<DOMElement> $children
     */
    $children = array_values(array_filter(iterator_to_array($enum->childNodes), fn(DOMNode $dn): bool => $dn instanceof DOMElement));
    if($children[0]->localName === "name") {
        $name = $children[0]->textContent;
        $symbols[$name] = "@@ENUM@@";
        $children = $enum->getElementsByTagName("decl");
        $defines[$name] = [];
        $lastValue = 0;
        foreach($children as $decl) {
            $children = array_values(array_filter(iterator_to_array($decl->childNodes), fn(DOMNode $dn): bool => $dn instanceof DOMElement));
            if(count($children) === 2) {

                // Let's just run C as PHP.  What could go wrong?
                $defines[$name][$children[0]->textContent] = $lastValue = eval("\$x " . str_replace("1U", "1", $children[1]->textContent) . "; return \$x;");
            } elseif(count($children) === 1) {
                $defines[$name][$children[0]->textContent] = ++$lastValue;
            }
        }
    }
}

// Remove duplicate newlines
$symbols = array_map(fn(string $s) => preg_replace('/^[ \t]*[\r\n]+/m', '', $s), $symbols);

$headerFile = fopen(__DIR__ . "/engine-$version.h", "w");
$constantsFile = fopen(__DIR__ . "/constants-$version.php", "w");

fwrite($headerFile, "#define FFI_SCOPE \"ZEngine\"\n#define FFI_LIB \"".($isWindows ? "php" . PHP_MAJOR_VERSION . ".dll" : "")."\"\n");
fwrite($constantsFile, "<?php\nnamespace ZEngine\Constants;\n");

foreach($symbolsToExport as $symbolName) {
    $symbolName = trim($symbolName);
    if(empty($symbolName)) continue;

    if($symbolName[0] === "#" || $symbolName[0] === "/") continue;
    $symbol = $symbols[$symbolName] ?? null;
    if(is_null($symbol)) {
        throw new RuntimeException("Could not find symbol $symbolName");
    }
    if($symbol === "@@ENUM@@") {
        // Symbol is an enum
        $name = ($symbolName === "__defines") ? "Defines" : $symbolName;
        // TODO in the future once PHP<8.0 is fully dead, consider switching this to enums
        fwrite($constantsFile, "class $name\n{\n\tprivate function __construct(){}\n");
        foreach($defines[$symbolName] as $key => $value) {
            if ($symbolName === "__defines") $key = substr($key, 1);
            fwrite($constantsFile, "\tpublic const $key = " . var_export($value, true) . ";\n");
        }
        fwrite($constantsFile, "}\n");
    } else {
        fwrite($headerFile, "$symbol\n");
    }
}
fclose($headerFile);
fclose($constantsFile);

FFI::cdef(file_get_contents(__DIR__ . "/engine-$version.h"));

//echo json_encode($symbols, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
// Turn into headers again: srcml out.xml | grep .
//$xml->save(__DIR__ . "/out.xml");