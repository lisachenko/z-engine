#define FFI_SCOPE "ZEngine"
#define FFI_LIB "ZEND_LIBRARY_NAME"

typedef int64_t zend_long;
typedef uint64_t zend_ulong;
typedef int64_t zend_off_t;

typedef unsigned char zend_bool;
typedef unsigned char zend_uchar;
typedef uintptr_t zend_type;

typedef enum {
  SUCCESS =  0,
  FAILURE = -1,        /* this MUST stay a negative number, or it may affect functions! */
} ZEND_RESULT_CODE;

typedef struct _zend_object_handlers zend_object_handlers;
typedef struct _zend_class_entry     zend_class_entry;
typedef union  _zend_function        zend_function;
typedef struct _zend_execute_data    zend_execute_data;

typedef struct _zval_struct     zval;

typedef struct _zend_refcounted zend_refcounted;
typedef struct _zend_string     zend_string;
typedef struct _zend_array      zend_array;
typedef struct _zend_object     zend_object;
typedef struct _zend_resource   zend_resource;
typedef struct _zend_reference  zend_reference;
typedef struct _zend_ast_ref    zend_ast_ref;
typedef struct _zend_ast        zend_ast;

typedef int  (*compare_func_t)(const void *, const void *);
typedef void (*swap_func_t)(void *, void *);
typedef void (*sort_func_t)(void *, size_t, size_t, compare_func_t, swap_func_t);
typedef void (*dtor_func_t)(zval *pDest);
typedef void (*copy_ctor_func_t)(zval *pElement);

typedef union _zend_value {
    zend_long         lval;                /* long value */
    double            dval;                /* double value */
    zend_refcounted  *counted;
    zend_string      *str;
    zend_array       *arr;
    zend_object      *obj;
    zend_resource    *res;
    zend_reference   *ref;
    zend_ast_ref     *ast;
    zval             *zv;
    void             *ptr;
    zend_class_entry *ce;
    zend_function    *func;
    struct {
        uint32_t w1;
        uint32_t w2;
    } ww;
} zend_value;

struct _zval_struct {
    zend_value        value;            /* value */
    union {
        struct {
            zend_uchar    type;            /* active type */
            zend_uchar    type_flags;
            union {
                uint16_t  extra;        /* not further specified */
            } u;
        } v;
        uint32_t type_info;
    } u1;
    union {
        uint32_t     next;                 /* hash collision chain */
        uint32_t     cache_slot;           /* cache slot (for RECV_INIT) */
        uint32_t     opline_num;           /* opline number (for FAST_CALL) */
        uint32_t     lineno;               /* line number (for ast nodes) */
        uint32_t     num_args;             /* arguments number for EX(This) */
        uint32_t     fe_pos;               /* foreach position */
        uint32_t     fe_iter_idx;          /* foreach iterator index */
        uint32_t     access_flags;         /* class constant access flags */
        uint32_t     property_guard;       /* single property guard */
        uint32_t     constant_flags;       /* constant flags */
        uint32_t     extra;                /* not further specified */
    } u2;
};

typedef struct _zend_refcounted_h {
    uint32_t         refcount;            /* reference counter 32-bit */
    union {
        uint32_t type_info;
    } u;
} zend_refcounted_h;

struct _zend_refcounted {
    zend_refcounted_h gc;
};

struct _zend_string {
    zend_refcounted_h gc;
    zend_ulong        h;                /* hash value */
    size_t            len;
    char              val[1];
};

typedef struct _Bucket {
    zval              val;
    zend_ulong        h;                /* hash value (or numeric index)   */
    zend_string      *key;              /* string key or NULL for numerics */
} Bucket;

typedef struct _zend_array HashTable;

struct _zend_array {
    zend_refcounted_h gc;
    union {
        struct {
            zend_uchar    flags;
            zend_uchar    _unused;
            zend_uchar    nIteratorsCount;
            zend_uchar    _unused2;
        } v;
        uint32_t flags;
    } u;
    uint32_t          nTableMask;
    Bucket           *arData;
    uint32_t          nNumUsed;
    uint32_t          nNumOfElements;
    uint32_t          nTableSize;
    uint32_t          nInternalPointer;
    zend_long         nNextFreeElement;
    dtor_func_t       pDestructor;
};

typedef uint32_t HashPosition;

typedef struct _HashTableIterator {
    HashTable    *ht;
    HashPosition  pos;
} HashTableIterator;

struct _zend_object {
    zend_refcounted_h gc;
    uint32_t          handle; // TODO: may be removed ???
    zend_class_entry *ce;
    const zend_object_handlers *handlers;
    HashTable        *properties;
    zval              properties_table[1];
};

struct _zend_resource {
    zend_refcounted_h gc;
    int               handle; // TODO: may be removed ???
    int               type;
    void             *ptr;
};

typedef struct _zend_property_info zend_property_info;

typedef struct {
    size_t num;
    size_t num_allocated;
    zend_property_info *ptr[1];
} zend_property_info_list;

typedef union {
    zend_property_info *ptr;
    uintptr_t list;
} zend_property_info_source_list;

struct _zend_reference {
    zend_refcounted_h              gc;
    zval                           val;
    zend_property_info_source_list sources;
};

struct _zend_ast_ref {
    zend_refcounted_h gc;
    /*zend_ast        ast; zend_ast follows the zend_ast_ref structure */
};

/* zend_ast.h */
typedef uint16_t zend_ast_kind;
typedef uint16_t zend_ast_attr;

struct _zend_ast {
    zend_ast_kind kind; /* Type of the node (ZEND_AST_* enum constant) */
    zend_ast_attr attr; /* Additional attribute, use depending on node type */
    uint32_t lineno;    /* Line number */
    zend_ast *child[1]; /* Array of children (using struct hack) */
};

/* Same as zend_ast, but with children count, which is updated dynamically */
typedef struct _zend_ast_list {
    zend_ast_kind kind;
    zend_ast_attr attr;
    uint32_t lineno;
    uint32_t children;
    zend_ast *child[1];
} zend_ast_list;

/* Lineno is stored in val.u2.lineno */
typedef struct _zend_ast_zval {
    zend_ast_kind kind;
    zend_ast_attr attr;
    zval val;
} zend_ast_zval;

/* Separate structure for function and class declaration, as they need extra information. */
typedef struct _zend_ast_decl {
    zend_ast_kind kind;
    zend_ast_attr attr; /* Unused - for structure compatibility */
    uint32_t start_lineno;
    uint32_t end_lineno;
    uint32_t flags;
    unsigned char *lex_pos;
    zend_string *doc_comment;
    zend_string *name;
    zend_ast *child[4];
} zend_ast_decl;

typedef void (*zend_ast_process_t)(zend_ast *ast);

/* zend_types.h */
typedef intptr_t zend_intptr_t;
typedef uintptr_t zend_uintptr_t;

/* zend_arena.h */
typedef struct _zend_arena zend_arena;

struct _zend_arena {
    char        *ptr;
    char        *end;
    zend_arena  *prev;
};

/* zend_compile.h */
typedef struct _zend_op_array zend_op_array;
typedef struct _zend_op zend_op;

typedef union _znode_op {
    uint32_t      constant;
    uint32_t      var;
    uint32_t      num;
    uint32_t      opline_num; /*  Needs to be signed */

    // We haven't support for #if..#endif constructions, so this resolved by hand
    // #if ZEND_USE_ABS_JMP_ADDR
    //    zend_op       *jmp_addr;
    // #else
        uint32_t      jmp_offset;
    // #endif
    // #if ZEND_USE_ABS_CONST_ADDR
    //    zval          *zv;
    // #endif
} znode_op;

typedef struct _znode { /* used only during compilation */
    zend_uchar op_type;
    zend_uchar flag;
    union {
        znode_op op;
        zval constant; /* replaced by literal/zv */
    } u;
} znode;

typedef struct _zend_ast_znode {
    zend_ast_kind kind;
    zend_ast_attr attr;
    uint32_t lineno;
    znode node;
} zend_ast_znode;

typedef struct _zend_declarables {
    zend_long ticks;
} zend_declarables;

typedef struct _zend_file_context {
    zend_declarables declarables;

    zend_string *current_namespace;
    zend_bool in_namespace;
    zend_bool has_bracketed_namespaces;

    HashTable *imports;
    HashTable *imports_function;
    HashTable *imports_const;

    HashTable seen_symbols;
} zend_file_context;

typedef union _zend_parser_stack_elem {
    zend_ast *ast;
    zend_string *str;
    zend_ulong num;
    unsigned char *ptr;
} zend_parser_stack_elem;

typedef int (*user_opcode_handler_t) (zend_execute_data *execute_data);

struct _zend_op {
    const void *handler;
    znode_op op1;
    znode_op op2;
    znode_op result;
    uint32_t extended_value;
    uint32_t lineno;
    zend_uchar opcode;
    zend_uchar op1_type;
    zend_uchar op2_type;
    zend_uchar result_type;
};

typedef struct _zend_brk_cont_element {
    int start;
    int cont;
    int brk;
    int parent;
    zend_bool is_switch;
} zend_brk_cont_element;

typedef struct _zend_label {
    int brk_cont;
    uint32_t opline_num;
} zend_label;

typedef struct _zend_try_catch_element {
    uint32_t try_op;
    uint32_t catch_op;  /* ketchup! */
    uint32_t finally_op;
    uint32_t finally_end;
} zend_try_catch_element;

typedef struct _zend_live_range {
    uint32_t var; /* low bits are used for variable type (ZEND_LIVE_* macros) */
    uint32_t start;
    uint32_t end;
} zend_live_range;

typedef struct _zend_oparray_context {
    uint32_t   opcodes_size;
    int        vars_size;
    int        literals_size;
    uint32_t   fast_call_var;
    uint32_t   try_catch_offset;
    int        current_brk_cont;
    int        last_brk_cont;
    zend_brk_cont_element *brk_cont_array;
    HashTable *labels;
} zend_oparray_context;

typedef struct _zend_property_info {
    uint32_t offset; /* property offset for object properties or
                          property index for static properties */
    uint32_t flags;
    zend_string *name;
    zend_string *doc_comment;
    zend_class_entry *ce;
    zend_type type;
} zend_property_info;

typedef struct _zend_class_constant {
    zval value; /* access flags are stored in reserved: zval.u2.access_flags */
    zend_string *doc_comment;
    zend_class_entry *ce;
} zend_class_constant;

/* arg_info for internal functions */
typedef struct _zend_internal_arg_info {
    const char *name;
    zend_type type;
    zend_uchar pass_by_reference;
    zend_bool is_variadic;
} zend_internal_arg_info;

/* arg_info for user functions */
typedef struct _zend_arg_info {
    zend_string *name;
    zend_type type;
    zend_uchar pass_by_reference;
    zend_bool is_variadic;
} zend_arg_info;

typedef struct _zend_internal_function_info {
    zend_uintptr_t required_num_args;
    zend_type type;
    zend_bool return_reference;
    zend_bool _is_variadic;
} zend_internal_function_info;

struct _zend_op_array {
    /* Common elements */
    zend_uchar type;
    zend_uchar arg_flags[3]; /* bitset of arg_info.pass_by_reference */
    uint32_t fn_flags;
    zend_string *function_name;
    zend_class_entry *scope;
    zend_function *prototype;
    uint32_t num_args;
    uint32_t required_num_args;
    zend_arg_info *arg_info;
    /* END of common elements */

    int cache_size;     /* number of run_time_cache_slots * sizeof(void*) */
    int last_var;       /* number of CV variables */
    uint32_t T;         /* number of temporary variables */
    uint32_t last;      /* number of opcodes */

    zend_op *opcodes;
    void ** * run_time_cache; ## __run_time_cache
    HashTable * * static_variables_ptr; ## __static_variables_ptr
    HashTable *static_variables;
    zend_string **vars; /* names of CV variables */

    uint32_t *refcount;

    int last_live_range;
    int last_try_catch;
    zend_live_range *live_range;
    zend_try_catch_element *try_catch_array;

    zend_string *filename;
    uint32_t line_start;
    uint32_t line_end;
    zend_string *doc_comment;

    int last_literal;
    zval *literals;

    void *reserved[ZEND_MAX_RESERVED_RESOURCES];
};

/* zend_internal_function_handler */
typedef void (*zif_handler)(INTERNAL_FUNCTION_PARAMETERS);

typedef struct _zend_internal_function {
    /* Common elements */
    zend_uchar type;
    zend_uchar arg_flags[3]; /* bitset of arg_info.pass_by_reference */
    uint32_t fn_flags;
    zend_string* function_name;
    zend_class_entry *scope;
    zend_function *prototype;
    uint32_t num_args;
    uint32_t required_num_args;
    zend_internal_arg_info *arg_info;
    /* END of common elements */

    zif_handler handler;
    struct _zend_module_entry *module;
    void *reserved[ZEND_MAX_RESERVED_RESOURCES];
} zend_internal_function;

union _zend_function {
    zend_uchar type;    /* MUST be the first element of this struct! */
    uint32_t   quick_arg_flags;

    struct {
        zend_uchar type;  /* never used */
        zend_uchar arg_flags[3]; /* bitset of arg_info.pass_by_reference */
        uint32_t fn_flags;
        zend_string *function_name;
        zend_class_entry *scope;
        zend_function *prototype;
        uint32_t num_args;
        uint32_t required_num_args;
        zend_arg_info *arg_info;
    } common;

    zend_op_array op_array;
    zend_internal_function internal_function;
};

typedef struct _zend_execute_data {
    const zend_op       *opline;           /* executed opline                */
    zend_execute_data   *call;             /* current call                   */
    zval                *return_value;
    zend_function       *func;             /* executed function              */
    zval                 This;             /* this + call_info + num_args    */
    zend_execute_data   *prev_execute_data;
    zend_array          *symbol_table;
    void               **run_time_cache;   /* cache op_array->run_time_cache */
};

/* zend_closurec.c */
typedef struct _zend_closure {
    zend_object       std;
    zend_function     func;
    zval              this_ptr;
    zend_class_entry *called_scope;
    zif_handler       orig_internal_handler;
} zend_closure;

/* zend_object_handlers.h */

extern const ZEND_API zend_object_handlers std_object_handlers;

/* The following rule applies to read_property() and read_dimension() implementations:
   If you return a zval which is not otherwise referenced by the extension or the engine's
   symbol table, its reference count should be 0.
*/
/* Used to fetch property from the object, read-only */
typedef zval *(*zend_object_read_property_t)(zval *object, zval *member, int type, void **cache_slot, zval *rv);

/* Used to fetch dimension from the object, read-only */
typedef zval *(*zend_object_read_dimension_t)(zval *object, zval *offset, int type, zval *rv);


/* The following rule applies to write_property() and write_dimension() implementations:
   If you receive a value zval in write_property/write_dimension, you may only modify it if
   its reference count is 1.  Otherwise, you must create a copy of that zval before making
   any changes.  You should NOT modify the reference count of the value passed to you.
   You must return the final value of the assigned property.
*/
/* Used to set property of the object */
typedef zval *(*zend_object_write_property_t)(zval *object, zval *member, zval *value, void **cache_slot);

/* Used to set dimension of the object */
typedef void (*zend_object_write_dimension_t)(zval *object, zval *offset, zval *value);


/* Used to create pointer to the property of the object, for future direct r/w access */
typedef zval *(*zend_object_get_property_ptr_ptr_t)(zval *object, zval *member, int type, void **cache_slot);

/* Used to set object value. Can be used to override assignments and scalar
   write ops (like ++, +=) on the object */
typedef void (*zend_object_set_t)(zval *object, zval *value);

/* Used to get object value. Can be used when converting object value to
 * one of the basic types and when using scalar ops (like ++, +=) on the object
 */
typedef zval* (*zend_object_get_t)(zval *object, zval *rv);

/* Used to check if a property of the object exists */
/* param has_set_exists:
 * 0 (has) whether property exists and is not NULL
 * 1 (set) whether property exists and is true
 * 2 (exists) whether property exists
 */
typedef int (*zend_object_has_property_t)(zval *object, zval *member, int has_set_exists, void **cache_slot);

/* Used to check if a dimension of the object exists */
typedef int (*zend_object_has_dimension_t)(zval *object, zval *member, int check_empty);

/* Used to remove a property of the object */
typedef void (*zend_object_unset_property_t)(zval *object, zval *member, void **cache_slot);

/* Used to remove a dimension of the object */
typedef void (*zend_object_unset_dimension_t)(zval *object, zval *offset);

/* Used to get hash of the properties of the object, as hash of zval's */
typedef HashTable *(*zend_object_get_properties_t)(zval *object);

typedef HashTable *(*zend_object_get_debug_info_t)(zval *object, int *is_temp);

typedef enum _zend_prop_purpose {
    /* Used for debugging. Supersedes get_debug_info handler. */
    ZEND_PROP_PURPOSE_DEBUG,
    /* Used for (array) casts. */
    ZEND_PROP_PURPOSE_ARRAY_CAST,
    /* Used for serialization using the "O" scheme.
     * Unserialization will use __wakeup(). */
    ZEND_PROP_PURPOSE_SERIALIZE,
    /* Used for var_export().
     * The data will be passed to __set_state() when evaluated. */
    ZEND_PROP_PURPOSE_VAR_EXPORT,
    /* Used for json_encode(). */
    ZEND_PROP_PURPOSE_JSON,
    /* array_key_exists(). Not intended for general use! */
    _ZEND_PROP_PURPOSE_ARRAY_KEY_EXISTS,
    /* Dummy member to ensure that "default" is specified. */
    _ZEND_PROP_PURPOSE_NON_EXHAUSTIVE_ENUM
} zend_prop_purpose;

/* The return value must be released using zend_release_properties(). */
typedef zend_array *(*zend_object_get_properties_for_t)(zval *object, zend_prop_purpose purpose);

/* Used to call methods */
/* args on stack! */
/* Andi - EX(fbc) (function being called) needs to be initialized already in the INIT fcall opcode so that the parameters can be parsed the right way. We need to add another callback for this.
 */
typedef int (*zend_object_call_method_t)(zend_string *method, zend_object *object, INTERNAL_FUNCTION_PARAMETERS);
typedef zend_function *(*zend_object_get_method_t)(zend_object **object, zend_string *method, const zval *key);
typedef zend_function *(*zend_object_get_constructor_t)(zend_object *object);

/* Object maintenance/destruction */
typedef void (*zend_object_dtor_obj_t)(zend_object *object);
typedef void (*zend_object_free_obj_t)(zend_object *object);
typedef zend_object* (*zend_object_clone_obj_t)(zval *object);

/* Get class name for display in var_dump and other debugging functions.
 * Must be defined and must return a non-NULL value. */
typedef zend_string *(*zend_object_get_class_name_t)(const zend_object *object);

typedef int (*zend_object_compare_t)(zval *object1, zval *object2);
typedef int (*zend_object_compare_zvals_t)(zval *result, zval *op1, zval *op2);

/* Cast an object to some other type.
 * readobj and retval must point to distinct zvals.
 */
typedef int (*zend_object_cast_t)(zval *readobj, zval *retval, int type);

/* updates *count to hold the number of elements present and returns SUCCESS.
 * Returns FAILURE if the object does not have any sense of overloaded dimensions */
typedef int (*zend_object_count_elements_t)(zval *object, zend_long *count);

typedef int (*zend_object_get_closure_t)(zval *obj, zend_class_entry **ce_ptr, zend_function **fptr_ptr, zend_object **obj_ptr);

typedef HashTable *(*zend_object_get_gc_t)(zval *object, zval **table, int *n);

typedef int (*zend_object_do_operation_t)(zend_uchar opcode, zval *result, zval *op1, zval *op2);

struct _zend_object_handlers {
    /* offset of real object header (usually zero) */
    int                                      offset;
    /* object handlers */
    zend_object_free_obj_t                  free_obj;             /* required */
    zend_object_dtor_obj_t                  dtor_obj;             /* required */
    zend_object_clone_obj_t                 clone_obj;            /* optional */
    zend_object_read_property_t             read_property;        /* required */
    zend_object_write_property_t            write_property;       /* required */
    zend_object_read_dimension_t            read_dimension;       /* required */
    zend_object_write_dimension_t           write_dimension;      /* required */
    zend_object_get_property_ptr_ptr_t      get_property_ptr_ptr; /* required */
    zend_object_get_t                       get;                  /* optional */
    zend_object_set_t                       set;                  /* optional */
    zend_object_has_property_t              has_property;         /* required */
    zend_object_unset_property_t            unset_property;       /* required */
    zend_object_has_dimension_t             has_dimension;        /* required */
    zend_object_unset_dimension_t           unset_dimension;      /* required */
    zend_object_get_properties_t            get_properties;       /* required */
    zend_object_get_method_t                get_method;           /* required */
    zend_object_call_method_t               call_method;          /* optional */
    zend_object_get_constructor_t           get_constructor;      /* required */
    zend_object_get_class_name_t            get_class_name;       /* required */
    zend_object_compare_t                   compare_objects;      /* optional */
    zend_object_cast_t                      cast_object;          /* optional */
    zend_object_count_elements_t            count_elements;       /* optional */
    zend_object_get_debug_info_t            get_debug_info;       /* optional */
    zend_object_get_closure_t               get_closure;          /* optional */
    zend_object_get_gc_t                    get_gc;               /* required */
    zend_object_do_operation_t              do_operation;         /* optional */
    zend_object_compare_zvals_t             compare;              /* optional */
    zend_object_get_properties_for_t        get_properties_for;   /* optional */
};

/* zend_llist.h*/
typedef struct _zend_llist_element {
    struct _zend_llist_element *next;
    struct _zend_llist_element *prev;
    char data[1]; /* Needs to always be last in the struct */
} zend_llist_element;

typedef void (*llist_dtor_func_t)(void *);
typedef int (*llist_compare_func_t)(const zend_llist_element **, const zend_llist_element **);
typedef void (*llist_apply_with_args_func_t)(void *data, int num_args, va_list args);
typedef void (*llist_apply_with_arg_func_t)(void *data, void *arg);
typedef void (*llist_apply_func_t)(void *);

typedef struct _zend_llist {
    zend_llist_element *head;
    zend_llist_element *tail;
    size_t count;
    size_t size;
    llist_dtor_func_t dtor;
    unsigned char persistent;
    zend_llist_element *traverse_ptr;
} zend_llist;

typedef zend_llist_element* zend_llist_position;

/* zend_multibyte.h */
typedef struct _zend_encoding zend_encoding;

/* zend_stack.h */
typedef struct _zend_stack {
    int size, top, max;
    void *elements;
} zend_stack;

/* zend_globals.h */
typedef struct _zend_vm_stack *zend_vm_stack;
typedef struct _zend_ini_entry zend_ini_entry;

typedef enum {
    ON_TOKEN,
    ON_FEEDBACK,
    ON_STOP
} zend_php_scanner_event;

/* zend_execute.h */
typedef struct _zend_vm_stack {
    zval *top;
    zval *end;
    zend_vm_stack prev;
};

/* zend_API.h */
typedef struct _zend_function_entry {
    const char *fname;
    zif_handler handler;
    const struct _zend_internal_arg_info *arg_info;
    uint32_t num_args;
    uint32_t flags;
} zend_function_entry;

typedef struct _zend_fcall_info {
    size_t size;
    zval function_name;
    zval *retval;
    zval *params;
    zend_object *object;
    zend_bool no_separation;
    uint32_t param_count;
} zend_fcall_info;

typedef struct _zend_fcall_info_cache {
    zend_function *function_handler;
    zend_class_entry *calling_scope;
    zend_class_entry *called_scope;
    zend_object *object;
} zend_fcall_info_cache;

/* zend_iterators.h */
typedef struct _zend_object_iterator zend_object_iterator;

typedef struct _zend_object_iterator_funcs {
    /* release all resources associated with this iterator instance */
    void (*dtor)(zend_object_iterator *iter);

    /* check for end of iteration (FAILURE or SUCCESS if data is valid) */
    int (*valid)(zend_object_iterator *iter);

    /* fetch the item data for the current element */
    zval *(*get_current_data)(zend_object_iterator *iter);

    /* fetch the key for the current element (optional, may be NULL). The key
     * should be written into the provided zval* using the ZVAL_* macros. If
     * this handler is not provided auto-incrementing integer keys will be
     * used. */
    void (*get_current_key)(zend_object_iterator *iter, zval *key);

    /* step forwards to next element */
    void (*move_forward)(zend_object_iterator *iter);

    /* rewind to start of data (optional, may be NULL) */
    void (*rewind)(zend_object_iterator *iter);

    /* invalidate current value/key (optional, may be NULL) */
    void (*invalidate_current)(zend_object_iterator *iter);
} zend_object_iterator_funcs;

struct _zend_object_iterator {
    zend_object std;
    zval data;
    const zend_object_iterator_funcs *funcs;
    zend_ulong index; /* private to fe_reset/fe_fetch opcodes */
};

typedef struct _zend_class_iterator_funcs {
    zend_function *zf_new_iterator;
    zend_function *zf_valid;
    zend_function *zf_current;
    zend_function *zf_key;
    zend_function *zf_next;
    zend_function *zf_rewind;
} zend_class_iterator_funcs;

/* zend.h */
struct _zend_serialize_data;
struct _zend_unserialize_data;

typedef struct _zend_serialize_data zend_serialize_data;
typedef struct _zend_unserialize_data zend_unserialize_data;

typedef struct _zend_class_name {
    zend_string *name;
    zend_string *lc_name;
} zend_class_name;

typedef struct _zend_trait_method_reference {
    zend_string *method_name;
    zend_string *class_name;
} zend_trait_method_reference;

typedef struct _zend_trait_precedence {
    zend_trait_method_reference trait_method;
    uint32_t num_excludes;
    zend_string *exclude_class_names[1];
} zend_trait_precedence;

typedef struct _zend_trait_alias {
    zend_trait_method_reference trait_method;

    /**
    * name for method to be added
    */
    zend_string *alias;

    /**
    * modifiers to be set on trait method
    */
    uint32_t modifiers;
} zend_trait_alias;

struct _zend_class_entry {
    char type;
    zend_string *name;
    /* class_entry or string depending on ZEND_ACC_LINKED */
    union {
        zend_class_entry *parent;
        zend_string *parent_name;
    };
    int refcount;
    uint32_t ce_flags;

    int default_properties_count;
    int default_static_members_count;
    zval *default_properties_table;
    zval *default_static_members_table;
    zval ** static_members_table;
    HashTable function_table;
    HashTable properties_info;
    HashTable constants_table;

    struct _zend_property_info **properties_info_table;

    zend_function *constructor;
    zend_function *destructor;
    zend_function *clone;
    zend_function *__get;
    zend_function *__set;
    zend_function *__unset;
    zend_function *__isset;
    zend_function *__call;
    zend_function *__callstatic;
    zend_function *__tostring;
    zend_function *__debugInfo;
    zend_function *serialize_func;
    zend_function *unserialize_func;

    /* allocated only if class implements Iterator or IteratorAggregate interface */
    zend_class_iterator_funcs *iterator_funcs_ptr;

    /* handlers */
    union {
        zend_object* (*create_object)(zend_class_entry *class_type);
        int (*interface_gets_implemented)(zend_class_entry *iface, zend_class_entry *class_type); /* a class implements this interface */
    };
    zend_object_iterator *(*get_iterator)(zend_class_entry *ce, zval *object, int by_ref);
    zend_function *(*get_static_method)(zend_class_entry *ce, zend_string* method);

    /* serializer callbacks */
    int (*serialize)(zval *object, unsigned char **buffer, size_t *buf_len, zend_serialize_data *data);
    int (*unserialize)(zval *object, zend_class_entry *ce, const unsigned char *buf, size_t buf_len, zend_unserialize_data *data);

    uint32_t num_interfaces;
    uint32_t num_traits;

    /* class_entry or string(s) depending on ZEND_ACC_LINKED */
    union {
        zend_class_entry **interfaces;
        zend_class_name *interface_names;
    };

    zend_class_name *trait_names;
    zend_trait_alias **trait_aliases;
    zend_trait_precedence **trait_precedences;

    union {
        struct {
            zend_string *filename;
            uint32_t line_start;
            uint32_t line_end;
            zend_string *doc_comment;
        } user;
        struct {
            const struct _zend_function_entry *builtin_functions;
            struct _zend_module_entry *module;
        } internal;
    } info;
};

typedef enum {
    EH_NORMAL = 0,
    EH_THROW
} zend_error_handling_t;

typedef struct {
    zend_error_handling_t  handling;
    zend_class_entry       *exception;
    zval                   user_handler;
} zend_error_handling;

/* zend_objects_API.h */
typedef struct _zend_objects_store {
    zend_object **object_buckets;
    uint32_t top;
    uint32_t size;
    int free_list_head;
} zend_objects_store;

/* zend_modules.h */
struct _zend_ini_entry;
typedef struct _zend_module_entry zend_module_entry;
typedef struct _zend_module_dep zend_module_dep;

struct _zend_module_entry {
    unsigned short size;
    unsigned int zend_api;
    unsigned char zend_debug;
    unsigned char zts;
    const struct _zend_ini_entry *ini_entry;
    const struct _zend_module_dep *deps;
    const char *name;
    const struct _zend_function_entry *functions;
    int (*module_startup_func)(int type, int module_number);
    int (*module_shutdown_func)(int type, int module_number);
    int (*request_startup_func)(int type, int module_number);
    int (*request_shutdown_func)(int type, int module_number);
    void (*info_func)(zend_module_entry *zend_module);
    const char *version;
    size_t globals_size;
#ifdef ZTS
    ts_rsrc_id* globals_id_ptr;
#endif
#ifndef ZTS
    void* globals_ptr;
#endif
    void (*globals_ctor)(void *global);
    void (*globals_dtor)(void *global);
    int (*post_deactivate_func)(void);
    int module_started;
    unsigned char type;
    void *handle;
    int module_number;
    const char *build_id;
};

struct _zend_module_dep {
    const char *name;		/* module name */
    const char *rel;		/* version relationship: NULL (exists), lt|le|eq|ge|gt (to given version) */
    const char *version;	/* version */
    unsigned char type;		/* dependency type */
};

/* zend_globals.h */
struct _zend_compiler_globals {
    zend_stack loop_var_stack;

    zend_class_entry *active_class_entry;

    zend_string *compiled_filename;

    int zend_lineno;

    zend_op_array *active_op_array;

    HashTable *function_table;    /* function symbol table */
    HashTable *class_table;       /* class table */

    HashTable filenames_table; /* List of loaded files */

    HashTable *auto_globals;  /* List of superglobal variables */

    zend_bool parse_error;
    zend_bool in_compilation;
    zend_bool short_tags;

    zend_bool unclean_shutdown;

    zend_bool ini_parser_unbuffered_errors;

    zend_llist open_files;

    struct _zend_ini_parser_param *ini_parser_param;

    zend_bool skip_shebang;
    zend_bool increment_lineno;

    zend_string *doc_comment;
    uint32_t extra_fn_flags;

    uint32_t compiler_options; /* set of ZEND_COMPILE_* constants */

    zend_oparray_context context;
    zend_file_context file_context;

    zend_arena *arena;

    HashTable interned_strings; /* Cache of all interned string */

    const zend_encoding **script_encoding_list;
    size_t script_encoding_list_size;
    zend_bool multibyte;
    zend_bool detect_unicode;
    zend_bool encoding_declared;

    zend_ast *ast;
    zend_arena *ast_arena;

    zend_stack delayed_oplines_stack;
    HashTable *memoized_exprs;
    int memoize_mode;

    void   *map_ptr_base;
    size_t  map_ptr_size;
    size_t  map_ptr_last;

    HashTable *delayed_variance_obligations;
    HashTable *delayed_autoloads;

    uint32_t rtd_key_counter;
};

#ifdef ZEND_WIN32
typedef struct _OSVERSIONINFOEXA {
    uint32_t dwOSVersionInfoSize;
    uint32_t dwMajorVersion;
    uint32_t dwMinorVersion;
    uint32_t dwBuildNumber;
    uint32_t dwPlatformId;
    char  szCSDVersion[128];
    uint16_t  wServicePackMajor;
    uint16_t  wServicePackMinor;
    uint16_t  wSuiteMask;
    char  wProductType;
    char  wReserved;
} OSVERSIONINFOEX;
#endif

struct _zend_executor_globals {
    zval uninitialized_zval;
    zval error_zval;

    /* symbol table cache */
    zend_array *symtable_cache[/* SYMTABLE_CACHE_SIZE */ 32];
    /* Pointer to one past the end of the symtable_cache */
    zend_array **symtable_cache_limit;
    /* Pointer to first unused symtable_cache slot */
    zend_array **symtable_cache_ptr;

    zend_array symbol_table;        /* main symbol table */

    HashTable included_files;    /* files already included */

    void *bailout;

    int error_reporting;
    int exit_status;

    HashTable *function_table;    /* function symbol table */
    HashTable *class_table;        /* class table */
    HashTable *zend_constants;    /* constants table */

    zval          *vm_stack_top; // Actually it's _zend_execute_data *
    zval          *vm_stack_end; // It's _zend_execute_data *
    zend_vm_stack  vm_stack;
    size_t         vm_stack_page_size;

    struct _zend_execute_data *current_execute_data;
    zend_class_entry *fake_scope; /* used to avoid checks accessing properties */

    zend_long precision;

    int ticks_count;

    uint32_t persistent_constants_count;
    uint32_t persistent_functions_count;
    uint32_t persistent_classes_count;

    HashTable *in_autoload;
    zend_function *autoload_func;
    zend_bool full_tables_cleanup;

    /* for extended information support */
    zend_bool no_extensions;

    zend_bool vm_interrupt;
    zend_bool timed_out;
    zend_long hard_timeout;

#ifdef ZEND_WIN32
    OSVERSIONINFOEX windows_version_info;
#endif

    HashTable regular_list;
    HashTable persistent_list;

    int user_error_handler_error_reporting;
    zval user_error_handler;
    zval user_exception_handler;
    zend_stack user_error_handlers_error_reporting;
    zend_stack user_error_handlers;
    zend_stack user_exception_handlers;

    zend_error_handling_t  error_handling;
    zend_class_entry      *exception_class;

    /* timeout support */
    zend_long timeout_seconds;

    int lambda_count;

    HashTable *ini_directives;
    HashTable *modified_ini_directives;
    zend_ini_entry *error_reporting_ini_entry;

    zend_objects_store objects_store;
    zend_object *exception, *prev_exception;
    const zend_op *opline_before_exception;
    zend_op exception_op[3];

    struct _zend_module_entry *current_module;

    zend_bool active;
    zend_uchar flags;

    zend_long assertions;

    uint32_t           ht_iterators_count;     /* number of allocatd slots */
    uint32_t           ht_iterators_used;      /* number of used slots */
    HashTableIterator *ht_iterators;
    HashTableIterator  ht_iterators_slots[16];

    void *saved_fpu_cw_ptr;

#ifdef XPFPA_HAVE_CW
    XPFPA_CW_DATATYPE saved_fpu_cw;
#endif

    zend_function trampoline;
    zend_op       call_trampoline_op;

    HashTable weakrefs;

    zend_bool exception_ignore_args;

    void *reserved[ZEND_MAX_RESERVED_RESOURCES];
};
typedef struct _zend_executor_globals zend_executor_globals;

#ifndef ZTS
ZEND_API zend_executor_globals executor_globals;
ZEND_API struct _zend_compiler_globals compiler_globals;
#endif

/* stdio.h */
typedef struct {
    int level; /* fill/empty level of buffer */
    unsigned flags; /* File status flags */
    char fd; /* File descriptor */
    unsigned char hold; /* Ungetc char if no buffer */
    int bsize; /* Buffer size */
    unsigned char *buffer; /* Data transfer buffer */
    unsigned char *curp; /* Current active pointer */
    unsigned istemp; /* Temporary file indicator */
    short token; /* Used for validity checking */
} FILE;

/* zend_stream.h */
typedef size_t (*zend_stream_fsizer_t)(void* handle);
typedef ssize_t (*zend_stream_reader_t)(void* handle, char *buf, size_t len);
typedef void   (*zend_stream_closer_t)(void* handle);

typedef enum {
    ZEND_HANDLE_FILENAME,
    ZEND_HANDLE_FP,
    ZEND_HANDLE_STREAM
} zend_stream_type;

typedef struct _zend_stream {
    void        *handle;
    int         isatty;
    zend_stream_reader_t   reader;
    zend_stream_fsizer_t   fsizer;
    zend_stream_closer_t   closer;
} zend_stream;

typedef struct _zend_file_handle {
    union {
        FILE          *fp;
        zend_stream   stream;
    } handle;
    const char        *filename;
    zend_string       *opened_path;
    zend_stream_type  type;
    /* free_filename is used by wincache */
    /* TODO: Clean up filename vs opened_path mess */
    zend_bool         free_filename;
    char              *buf;
    size_t            len;
} zend_file_handle;

/* zend_ptr_stack.h */
typedef struct _zend_ptr_stack {
    int top, max;
    void **elements;
    void **top_element;
    zend_bool persistent;
} zend_ptr_stack;

/* zend_multibyte.h */
typedef size_t (*zend_encoding_filter)(unsigned char **str, size_t *str_length, const unsigned char *buf, size_t length);

/* zend_language_scanner.h */
typedef struct _zend_lex_state {
    unsigned int yy_leng;
    unsigned char *yy_start;
    unsigned char *yy_text;
    unsigned char *yy_cursor;
    unsigned char *yy_marker;
    unsigned char *yy_limit;
    int yy_state;
    zend_stack state_stack;
    zend_ptr_stack heredoc_label_stack;

    zend_file_handle *in;
    uint32_t lineno;
    zend_string *filename;

    /* original (unfiltered) script */
    unsigned char *script_org;
    size_t script_org_size;

    /* filtered script */
    unsigned char *script_filtered;
    size_t script_filtered_size;

    /* input/output filters */
    zend_encoding_filter input_filter;
    zend_encoding_filter output_filter;
    const zend_encoding *script_encoding;

    /* hooks */
    void (*on_event)(zend_php_scanner_event event, int token, int line, void *context);
    void *on_event_context;

    zend_ast *ast;
    zend_arena *ast_arena;
} zend_lex_state;

typedef struct _zend_heredoc_label {
    char *label;
    int length;
    int indentation;
    zend_bool indentation_uses_spaces;
} zend_heredoc_label;

/**
 * Global hooks and variables
 */
extern ZEND_API zend_ast_process_t zend_ast_process;
extern ZEND_API HashTable module_registry;

/**
 * Zend Hash API
 */
ZEND_API int ZEND_FASTCALL zend_hash_del(HashTable *ht, zend_string *key);
ZEND_API zval ZEND_FASTCALL *zend_hash_find(const HashTable *ht, zend_string *key);
ZEND_API zval ZEND_FASTCALL *zend_hash_add_or_update(HashTable *ht, zend_string *key, zval *pData, uint32_t flag);

/**
 * Opcode API
 */
ZEND_API int zend_set_user_opcode_handler(zend_uchar opcode, user_opcode_handler_t handler);
ZEND_API user_opcode_handler_t zend_get_user_opcode_handler(zend_uchar opcode);

/**
 * Zend inheritance API
 */
ZEND_API void zend_do_inheritance_ex(zend_class_entry *ce, zend_class_entry *parent_ce, zend_bool checked);
ZEND_API zend_object ZEND_FASTCALL *zend_objects_new(zend_class_entry *ce);
ZEND_API void ZEND_FASTCALL zend_object_std_init(zend_object *object, zend_class_entry *ce);
ZEND_API void object_properties_init(zend_object *object, zend_class_entry *class_type);

/**
 * Language scanner API
 */
ZEND_API void zend_save_lexical_state(zend_lex_state *lex_state);
ZEND_API void zend_restore_lexical_state(zend_lex_state *lex_state);
ZEND_API int zend_prepare_string_for_scanning(zval *str, char *filename);
ZEND_API void zend_lex_tstring(zval *zv);

/**
 * Abstract Syntax Tree (AST) API
 */
ZEND_API int zendparse(void);
ZEND_API void ZEND_FASTCALL zend_ast_destroy(zend_ast *ast);
ZEND_API zend_ast ZEND_FASTCALL *zend_ast_create_list_0(zend_ast_kind kind);
ZEND_API zend_ast ZEND_FASTCALL *zend_ast_list_add(zend_ast *list, zend_ast *op);
ZEND_API zend_ast ZEND_FASTCALL *zend_ast_create_zval_ex(zval *zv, zend_ast_attr attr);
ZEND_API zend_ast ZEND_FASTCALL *zend_ast_create_0(zend_ast_kind kind);
ZEND_API zend_ast ZEND_FASTCALL *zend_ast_create_1(zend_ast_kind kind, zend_ast *child);
ZEND_API zend_ast ZEND_FASTCALL *zend_ast_create_2(zend_ast_kind kind, zend_ast *child1, zend_ast *child2);
ZEND_API zend_ast ZEND_FASTCALL *zend_ast_create_3(zend_ast_kind kind, zend_ast *child1, zend_ast *child2, zend_ast *child3);
ZEND_API zend_ast ZEND_FASTCALL *zend_ast_create_4(
    zend_ast_kind kind, zend_ast *child1, zend_ast *child2,
    zend_ast *child3, zend_ast *child4
);
ZEND_API zend_ast *zend_ast_create_decl(
    zend_ast_kind kind, uint32_t flags, uint32_t start_lineno, zend_string *doc_comment,
    zend_string *name, zend_ast *child0, zend_ast *child1, zend_ast *child2, zend_ast *child3
);

/**
 * Modules API
 */
ZEND_API zend_module_entry* zend_register_module_ex(zend_module_entry *module);
ZEND_API int zend_startup_module_ex(zend_module_entry *module);