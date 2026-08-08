<?php

declare(strict_types=1);

/**
 * Manifest of engine symbols exported into the generated FFI header.
 *
 * Keys:
 *   - types:      typedef/struct/enum names whose full definitions (and their
 *                 transitive dependencies) are emitted into engine.h
 *   - functions:  function symbols emitted as extern declarations
 *   - variables:  global variables emitted as extern declarations
 *   - defines:    C macro constants exported into constants.php (guarded by
 *                 #ifdef in the probe, so absent macros are skipped silently)
 *   - enums:      enum tag names whose members are exported into constants.php
 *   - opcode_header: header parsed textually for ZEND_<OPCODE> defines
 *   - layout_structs: typedef names measured by the C probe into layouts.json
 *                 (size + offset of every non-bitfield field). Every struct the
 *                 PHP code dereferences MUST be listed here - this is the
 *                 anti-segfault ground truth checked at Core::init().
 */
return [
    'types' => [
        // Fundamental value types
        'zval',
        'zend_refcounted',
        'zend_string',
        'zend_array',
        'HashTable',
        'Bucket',
        'zend_object',
        'zend_resource',
        'zend_reference',
        'zend_ast_ref',
        // Classes, functions, properties
        'zend_class_entry',
        'zend_class_constant',
        'zend_class_name',
        'zend_property_info',
        'zend_attribute',
        'zend_attribute_arg',
        'zend_function',
        'zend_op_array',
        'zend_internal_function',
        'zend_arg_info',
        'zend_internal_arg_info',
        'zend_type_list',
        // Engine-level constants (EG(zend_constants) entries)
        'zend_constant',
        // VM
        'zend_op',
        'zend_execute_data',
        'zend_objects_store',
        'user_opcode_handler_t',
        // Globals
        'zend_executor_globals',
        'zend_compiler_globals',
        // Modules
        'zend_module_entry',
        'zend_module_dep',
        // AST & compilation
        'zend_ast',
        'zend_ast_decl',
        'zend_ast_list',
        'zend_ast_zval',
        // PHP 8.5: constant expressions may embed a compiled closure body
        'zend_ast_op_array',
        'zend_ast_process_t',
        'zend_lex_state',
        'zend_arena',
        // Object handlers
        'zend_object_handlers',
        // Engine-level iterators (foreach over objects with ce->get_iterator)
        'zend_object_iterator',
        'zend_object_iterator_funcs',
        // Private engine structs injected via supplement.h (see Dockerfile)
        'zend_closure',
        // Compiled-script container (Zend/Optimizer/zend_optimizer.h, installed)
        'zend_script',
        // Private opcache structs injected via supplement.h: the persistent
        // script container and the file-cache binary header record
        'zend_persistent_script',
        'zend_file_cache_metainfo',
    ],

    'functions' => [
        // Hash API
        'zend_hash_del',
        'zend_hash_index_del',
        'zend_hash_find',
        'zend_hash_add_or_update',
        'zend_hash_index_add_or_update',
        'zend_hash_index_find',
        'zend_hash_destroy',
        // Opcode API
        'zend_set_user_opcode_handler',
        'zend_get_user_opcode_handler',
        // Inheritance / object API
        'zend_do_inheritance_ex',
        'zend_objects_new',
        'zend_object_std_init',
        'object_properties_init',
        'zend_objects_store_put',
        // Language scanner API
        'zend_save_lexical_state',
        'zend_restore_lexical_state',
        'zend_prepare_string_for_scanning',
        'zend_lex_tstring',
        // AST API
        'zendparse',
        'zend_ast_destroy',
        'zend_ast_create_list_0',
        'zend_ast_list_add',
        'zend_ast_create_zval_ex',
        'zend_ast_create_0',
        'zend_ast_create_1',
        'zend_ast_create_2',
        'zend_ast_create_3',
        'zend_ast_create_4',
        'zend_ast_create_5',
        'zend_ast_create_decl',
        // Function/class lifetime API (hot-swap support): destroy_op_array releases a
        // user function body (refcount-aware), zend_destroy_static_vars drops the
        // materialized static-variables table of an op_array, destroy_zend_class
        // releases a class entry the same way the engine class table destructor does,
        // and zend_array_dup mints an independent copy of an engine hashtable
        'destroy_op_array',
        'zend_destroy_static_vars',
        'destroy_zend_class',
        'zend_array_dup',
        // Iterator API (wraps a zend_object_iterator as an engine object)
        'zend_iterator_init',
        // Module API
        'zend_register_module_ex',
        'zend_startup_module_ex',
        // Constant API
        'zend_register_constant',
        // Exception API (clearing EG(exception) safely: releases the object and
        // restores opline_before_exception, unlike a raw EG(exception) = NULL)
        'zend_clear_exception',
        // Memory management API (the inline zend_string_init/release family is not linkable,
        // zend_string_concat2 is the pragmatic exported way to mint an owned zend_string)
        'zval_ptr_dtor',
        'zval_add_ref',
        'rc_dtor_func',
        'zend_string_concat2',
        'zend_string_hash_func',
        // libc free(): the ONLY way to release a malloc-backed block whose allocating
        // request is already over (FFI::free needs the original owning CData, which is a
        // PHP static and therefore request-scoped). Used by Core::persistentFree for
        // persistent blocks minted by z-engine or by the engine's pemalloc(..., 1).
        // Windows is the exception: symbols resolve through php8.dll there, and the
        // engine DLL does not re-export the CRT - Core::persistentFree binds free()
        // from ucrtbase.dll (the very heap the official builds allocate from) instead.
        ...(PHP_OS_FAMILY === 'Windows' ? [] : ['free']),
        // TSRM (ZTS builds only): the exported accessor for the calling thread's
        // local-storage block. EG/CG live at tsrm_get_ls_cache() + *_globals_offset -
        // the same fast path the engine's own EG()/CG() macros compile to (issue #60).
        ...(ZEND_THREAD_SAFE ? ['tsrm_get_ls_cache'] : []),
    ],

    'variables' => [
        // EG/CG storage: plain extern symbols on NTS; on ZTS the globals are
        // per-thread and only the TSRM byte offsets are exported
        // (Zend/zend_globals.h) - the structs are reached through
        // tsrm_get_ls_cache(). The _id resource ids are deliberately not
        // exported: the offsets path never needs them, and the __thread TLS
        // cache variable itself cannot be bound by FFI at all.
        ...(ZEND_THREAD_SAFE
            ? ['executor_globals_offset', 'compiler_globals_offset']
            : ['executor_globals', 'compiler_globals']),
        'module_registry',
        'std_object_handlers',
        'zend_ast_process',
        // Engine hook points for debugger-grade tooling: the error callback
        // (zend_error_cb, fires for every engine error/warning/notice), the
        // exception-throw hook (zend_throw_exception_hook, fires inside
        // zend_throw_exception_internal) and the VM interrupt callback
        // (zend_interrupt_function, fires at the next interrupt check after
        // EG(vm_interrupt) is raised - the engine's async "break" primitive)
        'zend_error_cb',
        'zend_throw_exception_hook',
        'zend_interrupt_function',
        // 32 hex chars identifying the exact engine build (Zend/zend_system_id.h);
        // opcache stamps it into every file-cache binary header
        'zend_system_id',
    ],

    'defines' => [
        // Class/function/property flags (zend_compile.h)
        'ZEND_ACC_PUBLIC', 'ZEND_ACC_PROTECTED', 'ZEND_ACC_PRIVATE', 'ZEND_ACC_CHANGED',
        'ZEND_ACC_STATIC', 'ZEND_ACC_ABSTRACT', 'ZEND_ACC_FINAL', 'ZEND_ACC_DEPRECATED',
        'ZEND_ACC_INTERFACE', 'ZEND_ACC_TRAIT', 'ZEND_ACC_ANON_CLASS', 'ZEND_ACC_ENUM',
        'ZEND_ACC_IMPLICIT_ABSTRACT_CLASS', 'ZEND_ACC_LINKED', 'ZEND_ACC_IMMUTABLE',
        'ZEND_ACC_USE_GUARDS', 'ZEND_ACC_CONSTANTS_UPDATED', 'ZEND_ACC_NO_DYNAMIC_PROPERTIES',
        'ZEND_ACC_HAS_STATIC_IN_METHODS', 'ZEND_HAS_STATIC_IN_METHODS',
        'ZEND_ACC_TOP_LEVEL', 'ZEND_ACC_PRELOADED',
        'ZEND_ACC_NOT_SERIALIZABLE', 'ZEND_ACC_READONLY_CLASS', 'ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES',
        'ZEND_ACC_UNRESOLVED_VARIANCE', 'ZEND_ACC_NEARLY_LINKED', 'ZEND_ACC_RESOLVED_PARENT',
        'ZEND_ACC_RESOLVED_INTERFACES', 'ZEND_ACC_HAS_UNLINKED_USES', 'ZEND_ACC_PROPERTY_TYPES_RESOLVED',
        'ZEND_ACC_REUSE_GET_ITERATOR', 'ZEND_ACC_EXPLICIT_ABSTRACT_CLASS',
        'ZEND_ACC_READONLY', 'ZEND_ACC_ABSTRACT_METHOD', 'ZEND_ACC_VIRTUAL',
        'ZEND_ACC_FAKE_CLOSURE', 'ZEND_ACC_UNINSTANTIABLE',
        'ZEND_ACC_CALL_VIA_TRAMPOLINE', 'ZEND_ACC_NEVER_CACHE', 'ZEND_ACC_TRAIT_CLONE',
        'ZEND_ACC_RETURN_REFERENCE', 'ZEND_ACC_DONE_PASS_TWO', 'ZEND_ACC_HEAP_RT_CACHE',
        'ZEND_ACC_STRICT_TYPES', 'ZEND_ACC_CLOSURE', 'ZEND_ACC_GENERATOR',
        'ZEND_ACC_HAS_FINALLY_BLOCK', 'ZEND_ACC_EARLY_BINDING', 'ZEND_ACC_USES_THIS',
        'ZEND_ACC_CTOR', 'ZEND_ACC_HAS_TYPE_HINTS', 'ZEND_ACC_HAS_RETURN_TYPE',
        'ZEND_ACC_VARIADIC', 'ZEND_ACC_HAS_UNRESOLVED_INITIALIZERS',
        'ZEND_ACC_CACHED', 'ZEND_ACC_FILE_CACHED', 'ZEND_ACC_ARENA_ALLOCATED',
        // zend_type representation (zend_types.h): kind/ownership bits packed into
        // zend_type.type_mask next to the MAY_BE_* value mask
        '_ZEND_TYPE_EXTRA_FLAGS_SHIFT', '_ZEND_TYPE_MASK', '_ZEND_TYPE_NAME_BIT',
        '_ZEND_TYPE_LITERAL_NAME_BIT', '_ZEND_TYPE_LIST_BIT', '_ZEND_TYPE_KIND_MASK',
        '_ZEND_TYPE_ITERABLE_BIT', '_ZEND_TYPE_ARENA_BIT', '_ZEND_TYPE_INTERSECTION_BIT',
        '_ZEND_TYPE_UNION_BIT', '_ZEND_TYPE_NULLABLE_BIT', '_ZEND_TYPE_MAY_BE_MASK',
        // zval types (zend_types.h)
        'IS_UNDEF', 'IS_NULL', 'IS_FALSE', 'IS_TRUE', 'IS_LONG', 'IS_DOUBLE', 'IS_STRING',
        'IS_ARRAY', 'IS_OBJECT', 'IS_RESOURCE', 'IS_REFERENCE', 'IS_CONSTANT_AST',
        'IS_CALLABLE', 'IS_ITERABLE', 'IS_VOID', 'IS_STATIC', 'IS_MIXED', 'IS_NEVER',
        'IS_INDIRECT', 'IS_PTR', 'IS_ALIAS_PTR', '_IS_ERROR', '_IS_BOOL', '_IS_NUMBER',
        'IS_TYPE_REFCOUNTED', 'IS_TYPE_COLLECTABLE', 'Z_TYPE_MASK', 'Z_TYPE_FLAGS_MASK',
        'Z_TYPE_FLAGS_SHIFT',
        // GC (zend_types.h)
        'GC_TYPE_MASK', 'GC_FLAGS_MASK', 'GC_INFO_MASK', 'GC_FLAGS_SHIFT', 'GC_INFO_SHIFT',
        'GC_NULL', 'GC_STRING', 'GC_ARRAY', 'GC_OBJECT', 'GC_RESOURCE', 'GC_REFERENCE',
        'GC_CONSTANT_AST', 'GC_NOT_COLLECTABLE', 'GC_PROTECTED', 'GC_IMMUTABLE',
        'GC_PERSISTENT', 'GC_PERSISTENT_LOCAL',
        'IS_STR_CLASS_NAME_MAP_PTR', 'IS_STR_INTERNED', 'IS_STR_PERSISTENT',
        'IS_STR_PERMANENT', 'IS_STR_VALID_UTF8',
        'IS_ARRAY_IMMUTABLE', 'IS_ARRAY_PERSISTENT',
        'IS_OBJ_WEAKLY_REFERENCED', 'IS_OBJ_DESTRUCTOR_CALLED', 'IS_OBJ_FREE_CALLED',
        // Lazy objects (PHP 8.4, zend_types.h; tested against obj->extra_flags aka OBJ_EXTRA_FLAGS)
        'IS_OBJ_LAZY_UNINITIALIZED', 'IS_OBJ_LAZY_PROXY',
        // HashTable flags (zend_hash.h / zend_types.h)
        'HASH_FLAG_CONSISTENCY', 'HASH_FLAG_PACKED', 'HASH_FLAG_UNINITIALIZED',
        'HASH_FLAG_STATIC_KEYS', 'HASH_FLAG_HAS_EMPTY_IND', 'HASH_FLAG_ALLOW_COW_VIOLATION',
        'HASH_UPDATE', 'HASH_ADD', 'HASH_UPDATE_INDIRECT', 'HASH_ADD_NEW', 'HASH_ADD_NEXT',
        'HT_MIN_MASK', 'HT_MIN_SIZE',
        // Module API (zend_modules.h)
        'ZEND_MODULE_API_NO', 'MODULE_PERSISTENT', 'MODULE_TEMPORARY',
        // Constant flags (zend_constants.h); the flags and the module number are
        // packed into zval.u2.constant_flags of zend_constant.value: the low byte
        // holds CONST_* flags (ZEND_CONSTANT_FLAGS), the upper bits hold the
        // module number (ZEND_CONSTANT_MODULE_NUMBER, PHP_USER_CONSTANT for
        // userland define()d constants)
        'CONST_CS', 'CONST_PERSISTENT', 'CONST_NO_FILE_CACHE', 'CONST_DEPRECATED',
        'CONST_OWNED', 'CONST_RECURSIVE', 'PHP_USER_CONSTANT',
        // Engine build info
        'ZEND_DEBUG', 'ZEND_MM_ALIGNMENT', 'ZEND_MAX_RESERVED_RESOURCES',
        // Function kinds (zend_compile.h)
        'ZEND_INTERNAL_FUNCTION', 'ZEND_USER_FUNCTION', 'ZEND_EVAL_CODE',
        // Compiler options (zend_compile.h)
        'ZEND_COMPILE_EXTENDED_STMT', 'ZEND_COMPILE_EXTENDED_FCALL', 'ZEND_COMPILE_EXTENDED_INFO',
        'ZEND_COMPILE_HANDLE_OP_ARRAY', 'ZEND_COMPILE_IGNORE_INTERNAL_FUNCTIONS',
        'ZEND_COMPILE_DELAYED_BINDING', 'ZEND_COMPILE_NO_CONSTANT_SUBSTITUTION',
        'ZEND_COMPILE_IGNORE_INTERNAL_CLASSES', 'ZEND_COMPILE_IGNORE_USER_FUNCTIONS',
        'ZEND_COMPILE_GUARDS', 'ZEND_COMPILE_NO_BUILTINS', 'ZEND_COMPILE_NO_JUMPTABLES',
        'ZEND_COMPILE_PRELOAD', 'ZEND_COMPILE_PRELOAD_IN_CHILD', 'ZEND_COMPILE_WITHOUT_EXECUTION',
        // Call frame (zend_compile.h)
        'ZEND_CALL_FUNCTION', 'ZEND_CALL_CODE', 'ZEND_CALL_NESTED', 'ZEND_CALL_TOP',
        'ZEND_CALL_HAS_THIS', 'ZEND_CALL_FAKE_CLOSURE', 'ZEND_CALL_CLOSURE',
        'ZEND_CALL_HAS_SYMBOL_TABLE',
        // Attribute targets/flags (zend_attributes.h)
        'ZEND_ATTRIBUTE_TARGET_CLASS', 'ZEND_ATTRIBUTE_TARGET_FUNCTION', 'ZEND_ATTRIBUTE_TARGET_METHOD',
        'ZEND_ATTRIBUTE_TARGET_PROPERTY', 'ZEND_ATTRIBUTE_TARGET_CLASS_CONST', 'ZEND_ATTRIBUTE_TARGET_PARAMETER',
        'ZEND_ATTRIBUTE_TARGET_CONST', 'ZEND_ATTRIBUTE_TARGET_ALL', 'ZEND_ATTRIBUTE_IS_REPEATABLE',
        'ZEND_ATTRIBUTE_FLAGS',
    ],

    'enums' => [
        '_zend_ast_kind',
        // Anonymous enum aliased by its typedef name (ZEND_PROPERTY_HOOK_GET/SET)
        'zend_property_hook_kind',
    ],

    // System/libc types that z-engine only ever uses behind a pointer. Their
    // full definitions must NOT be emitted: their layout is libc-version
    // specific (e.g. glibc's struct _IO_FILE differs between releases), which
    // would make the generated header non-reproducible across build hosts.
    // Emitting only a forward declaration keeps the header stable and is all
    // FFI needs for pointer usage.
    //
    // The list is a union across platforms: entries are matched by name
    // against the build's clang AST, so a name that does not exist on the
    // current libc (glibc's _IO_FILE on Darwin, Darwin's __sFILE on glibc)
    // is simply never matched and does not affect the emitted header.
    'opaque' => [
        'FILE',
        '_IO_FILE', // glibc
        '__sFILE',  // Darwin libc
        '_iobuf',   // MSVC UCRT
    ],

    'opcode_header' => 'Zend/zend_vm_opcodes.h',

    'layout_structs' => [
        'zval',
        'zend_refcounted',
        'zend_string',
        'zend_array',
        'Bucket',
        'zend_object',
        'zend_resource',
        'zend_reference',
        'zend_class_entry',
        'zend_class_constant',
        'zend_class_name',
        'zend_property_info',
        'zend_type_list',
        'zend_constant',
        'zend_attribute',
        'zend_attribute_arg',
        'zend_op_array',
        'zend_internal_function',
        'zend_op',
        'zend_execute_data',
        'zend_objects_store',
        'zend_executor_globals',
        'zend_compiler_globals',
        'zend_module_entry',
        'zend_module_dep',
        'zend_object_handlers',
        'zend_object_iterator',
        'zend_object_iterator_funcs',
        'zend_ast',
        'zend_ast_decl',
        'zend_ast_list',
        'zend_ast_zval',
        'zend_ast_op_array',
        'zend_lex_state',
        'zend_closure',
        'zend_script',
        'zend_error_info',
        'zend_early_binding',
        'zend_persistent_script',
        'zend_file_cache_metainfo',
    ],
];
