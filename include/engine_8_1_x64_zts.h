#define FFI_SCOPE "ZEngine"
#define FFI_LIB "ZEND_LIBRARY_NAME"
typedef int64_t zend_long;
typedef uint64_t zend_ulong;
typedef int64_t zend_off_t;
typedef _Bool zend_bool;
typedef unsigned char zend_uchar;
typedef struct {
 void *ptr;
 uint32_t type_mask;
} zend_type;
typedef enum {
  SUCCESS = 0,
  FAILURE = -1, 
} ZEND_RESULT_CODE;
typedef struct _zend_object_handlers zend_object_handlers;
typedef struct _zend_class_entry zend_class_entry;
typedef union _zend_function zend_function;
typedef struct _zend_execute_data zend_execute_data;
typedef struct _zval_struct zval;
typedef struct _zend_refcounted zend_refcounted;
typedef struct _zend_string zend_string;
typedef struct _zend_array zend_array;
typedef struct _zend_object zend_object;
typedef struct _zend_resource zend_resource;
typedef struct _zend_reference zend_reference;
typedef struct _zend_ast_ref zend_ast_ref;
typedef struct _zend_ast zend_ast;
typedef int (*compare_func_t)(const void *, const void *);
typedef void (*swap_func_t)(void *, void *);
typedef void (*sort_func_t)(void *, size_t, size_t, compare_func_t, swap_func_t);
typedef void (*dtor_func_t)(zval *pDest);
typedef void (*copy_ctor_func_t)(zval *pElement);
typedef union _zend_value {
 zend_long lval; 
 double dval; 
 zend_refcounted *counted;
 zend_string *str;
 zend_array *arr;
 zend_object *obj;
 zend_resource *res;
 zend_reference *ref;
 zend_ast_ref *ast;
 zval *zv;
 void *ptr;
 zend_class_entry *ce;
 zend_function *func;
 struct {
  uint32_t w1;
  uint32_t w2;
 } ww;
} zend_value;
struct _zval_struct {
 zend_value value; 
 union {
  uint32_t type_info;
  struct {
   zend_uchar type;  zend_uchar type_flags; union { uint16_t extra;  } u;
  } v;
 } u1;
 union {
  uint32_t next; 
  uint32_t cache_slot; 
  uint32_t opline_num; 
  uint32_t lineno; 
  uint32_t num_args; 
  uint32_t fe_pos; 
  uint32_t fe_iter_idx; 
  uint32_t property_guard; 
  uint32_t constant_flags; 
  uint32_t extra; 
 } u2;
};
typedef struct _zend_refcounted_h {
 uint32_t refcount; 
 union {
  uint32_t type_info;
 } u;
} zend_refcounted_h;
struct _zend_refcounted {
 zend_refcounted_h gc;
};
struct _zend_string {
 zend_refcounted_h gc;
 zend_ulong h; 
 size_t len;
 char val[1];
};
typedef struct _Bucket {
 zval val;
 zend_ulong h; 
 zend_string *key; 
} Bucket;
typedef struct _zend_array HashTable;
struct _zend_array {
 zend_refcounted_h gc;
 union {
  struct {
   zend_uchar flags; zend_uchar _unused; zend_uchar nIteratorsCount; zend_uchar _unused2;
  } v;
  uint32_t flags;
 } u;
 uint32_t nTableMask;
 Bucket *arData;
 uint32_t nNumUsed;
 uint32_t nNumOfElements;
 uint32_t nTableSize;
 uint32_t nInternalPointer;
 zend_long nNextFreeElement;
 dtor_func_t pDestructor;
};
typedef uint32_t HashPosition;
typedef struct _HashTableIterator {
 HashTable *ht;
 HashPosition pos;
} HashTableIterator;
struct _zend_object {
 zend_refcounted_h gc;
 uint32_t handle; 
 zend_class_entry *ce;
 const zend_object_handlers *handlers;
 HashTable *properties;
 zval properties_table[1];
};
struct _zend_resource {
 zend_refcounted_h gc;
 zend_long handle; 
 int type;
 void *ptr;
};
typedef struct {
 size_t num;
 size_t num_allocated;
 struct _zend_property_info *ptr[1];
} zend_property_info_list;
typedef union {
 struct _zend_property_info *ptr;
 uintptr_t list;
} zend_property_info_source_list;
struct _zend_reference {
 zend_refcounted_h gc;
 zval val;
 zend_property_info_source_list sources;
};
struct _zend_ast_ref {
 zend_refcounted_h gc;
};
typedef uint16_t zend_ast_kind;
typedef uint16_t zend_ast_attr;
struct _zend_ast {
 zend_ast_kind kind; 
 zend_ast_attr attr; 
 uint32_t lineno; 
 zend_ast *child[1]; 
};
typedef struct _zend_ast_list {
 zend_ast_kind kind;
 zend_ast_attr attr;
 uint32_t lineno;
 uint32_t children;
 zend_ast *child[1];
} zend_ast_list;
typedef struct _zend_ast_zval {
 zend_ast_kind kind;
 zend_ast_attr attr;
 zval val;
} zend_ast_zval;
typedef struct _zend_ast_decl {
 zend_ast_kind kind;
 zend_ast_attr attr; 
 uint32_t start_lineno;
 uint32_t end_lineno;
 uint32_t flags;
 unsigned char *lex_pos;
 zend_string *doc_comment;
 zend_string *name;
 zend_ast *child[5];
} zend_ast_decl;
typedef void (*zend_ast_process_t)(zend_ast *ast);
typedef intptr_t zend_intptr_t;
typedef uintptr_t zend_uintptr_t;
typedef struct _zend_arena zend_arena;
struct _zend_arena {
 char *ptr;
 char *end;
 zend_arena *prev;
};
typedef struct _zend_op_array zend_op_array;
typedef struct _zend_op zend_op;
typedef union _znode_op {
 uint32_t constant;
 uint32_t var;
 uint32_t num;
 uint32_t opline_num; 
 uint32_t jmp_offset;
} znode_op;
typedef struct _znode { 
 zend_uchar op_type;
 zend_uchar flag;
 union {
  znode_op op;
  zval constant; 
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
 _Bool in_namespace;
 _Bool has_bracketed_namespaces;
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
 unsigned char *ident;
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
 _Bool is_switch;
} zend_brk_cont_element;
typedef struct _zend_label {
 int brk_cont;
 uint32_t opline_num;
} zend_label;
typedef struct _zend_try_catch_element {
 uint32_t try_op;
 uint32_t catch_op; 
 uint32_t finally_op;
 uint32_t finally_end;
} zend_try_catch_element;
typedef struct _zend_live_range {
 uint32_t var; 
 uint32_t start;
 uint32_t end;
} zend_live_range;
typedef struct _zend_oparray_context {
 uint32_t opcodes_size;
 int vars_size;
 int literals_size;
 uint32_t fast_call_var;
 uint32_t try_catch_offset;
 int current_brk_cont;
 int last_brk_cont;
 zend_brk_cont_element *brk_cont_array;
 HashTable *labels;
} zend_oparray_context;
typedef struct _zend_property_info {
 uint32_t offset; 
 uint32_t flags;
 zend_string *name;
 zend_string *doc_comment;
 HashTable *attributes;
 zend_class_entry *ce;
 zend_type type;
} zend_property_info;
typedef struct _zend_class_constant {
 zval value; 
 zend_string *doc_comment;
 HashTable *attributes;
 zend_class_entry *ce;
} zend_class_constant;
typedef struct _zend_internal_arg_info {
 const char *name;
 zend_type type;
 const char *default_value;
} zend_internal_arg_info;
typedef struct _zend_arg_info {
 zend_string *name;
 zend_type type;
 zend_string *default_value;
} zend_arg_info;
typedef struct _zend_internal_function_info {
 zend_uintptr_t required_num_args;
 zend_type type;
 const char *default_value;
} zend_internal_function_info;
struct _zend_op_array {
 zend_uchar type;
 zend_uchar arg_flags[3]; 
 uint32_t fn_flags;
 zend_string *function_name;
 zend_class_entry *scope;
 zend_function *prototype;
 uint32_t num_args;
 uint32_t required_num_args;
 zend_arg_info *arg_info;
 HashTable *attributes;
 int cache_size; 
 int last_var; 
 uint32_t T; 
 uint32_t last; 
 zend_op *opcodes;
 void ** * run_time_cache__ptr;
 HashTable * * static_variables_ptr__ptr;
 HashTable *static_variables;
 zend_string **vars; 
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
 uint32_t num_dynamic_func_defs;
 zval *literals;
 zend_op_array **dynamic_func_defs;
 void *reserved[6];
};
typedef void ( *zif_handler)(zend_execute_data *execute_data, zval *return_value);
typedef struct _zend_internal_function {
 zend_uchar type;
 zend_uchar arg_flags[3]; 
 uint32_t fn_flags;
 zend_string* function_name;
 zend_class_entry *scope;
 zend_function *prototype;
 uint32_t num_args;
 uint32_t required_num_args;
 zend_internal_arg_info *arg_info;
 HashTable *attributes;
 zif_handler handler;
 struct _zend_module_entry *module;
 void *reserved[6];
} zend_internal_function;
union _zend_function {
 zend_uchar type; 
 uint32_t quick_arg_flags;
 struct {
  zend_uchar type; 
  zend_uchar arg_flags[3]; 
  uint32_t fn_flags;
  zend_string *function_name;
  zend_class_entry *scope;
  zend_function *prototype;
  uint32_t num_args;
  uint32_t required_num_args;
  zend_arg_info *arg_info; 
  HashTable *attributes;
 } common;
 zend_op_array op_array;
 zend_internal_function internal_function;
};
struct _zend_execute_data {
 const zend_op *opline; 
 zend_execute_data *call; 
 zval *return_value;
 zend_function *func; 
 zval This; 
 zend_execute_data *prev_execute_data;
 zend_array *symbol_table;
 void **run_time_cache; 
 zend_array *extra_named_params;
};
typedef struct _zend_closure {
 zend_object std;
 zend_function func;
 zval this_ptr;
 zend_class_entry *called_scope;
 zif_handler orig_internal_handler;
} zend_closure;
extern const zend_object_handlers std_object_handlers;
typedef zval *(*zend_object_read_property_t)(zend_object *object, zend_string *member, int type, void **cache_slot, zval *rv);
typedef zval *(*zend_object_read_dimension_t)(zend_object *object, zval *offset, int type, zval *rv);
typedef zval *(*zend_object_write_property_t)(zend_object *object, zend_string *member, zval *value, void **cache_slot);
typedef void (*zend_object_write_dimension_t)(zend_object *object, zval *offset, zval *value);
typedef zval *(*zend_object_get_property_ptr_ptr_t)(zend_object *object, zend_string *member, int type, void **cache_slot);
typedef int (*zend_object_has_property_t)(zend_object *object, zend_string *member, int has_set_exists, void **cache_slot);
typedef int (*zend_object_has_dimension_t)(zend_object *object, zval *member, int check_empty);
typedef void (*zend_object_unset_property_t)(zend_object *object, zend_string *member, void **cache_slot);
typedef void (*zend_object_unset_dimension_t)(zend_object *object, zval *offset);
typedef HashTable *(*zend_object_get_properties_t)(zend_object *object);
typedef HashTable *(*zend_object_get_debug_info_t)(zend_object *object, int *is_temp);
typedef enum _zend_prop_purpose {
 ZEND_PROP_PURPOSE_DEBUG,
 ZEND_PROP_PURPOSE_ARRAY_CAST,
 ZEND_PROP_PURPOSE_SERIALIZE,
 ZEND_PROP_PURPOSE_VAR_EXPORT,
 ZEND_PROP_PURPOSE_JSON,
 _ZEND_PROP_PURPOSE_NON_EXHAUSTIVE_ENUM
} zend_prop_purpose;
typedef zend_array *(*zend_object_get_properties_for_t)(zend_object *object, zend_prop_purpose purpose);
typedef zend_function *(*zend_object_get_method_t)(zend_object **object, zend_string *method, const zval *key);
typedef zend_function *(*zend_object_get_constructor_t)(zend_object *object);
typedef void (*zend_object_dtor_obj_t)(zend_object *object);
typedef void (*zend_object_free_obj_t)(zend_object *object);
typedef zend_object* (*zend_object_clone_obj_t)(zend_object *object);
typedef zend_string *(*zend_object_get_class_name_t)(const zend_object *object);
typedef int (*zend_object_compare_t)(zval *object1, zval *object2);
typedef int (*zend_object_cast_t)(zend_object *readobj, zval *retval, int type);
typedef int (*zend_object_count_elements_t)(zend_object *object, zend_long *count);
typedef int (*zend_object_get_closure_t)(zend_object *obj, zend_class_entry **ce_ptr, zend_function **fptr_ptr, zend_object **obj_ptr, _Bool check_only);
typedef HashTable *(*zend_object_get_gc_t)(zend_object *object, zval **table, int *n);
typedef int (*zend_object_do_operation_t)(zend_uchar opcode, zval *result, zval *op1, zval *op2);
struct _zend_object_handlers {
 int offset;
 zend_object_free_obj_t free_obj; 
 zend_object_dtor_obj_t dtor_obj; 
 zend_object_clone_obj_t clone_obj; 
 zend_object_read_property_t read_property; 
 zend_object_write_property_t write_property; 
 zend_object_read_dimension_t read_dimension; 
 zend_object_write_dimension_t write_dimension; 
 zend_object_get_property_ptr_ptr_t get_property_ptr_ptr; 
 zend_object_has_property_t has_property; 
 zend_object_unset_property_t unset_property; 
 zend_object_has_dimension_t has_dimension; 
 zend_object_unset_dimension_t unset_dimension; 
 zend_object_get_properties_t get_properties; 
 zend_object_get_method_t get_method; 
 zend_object_get_constructor_t get_constructor; 
 zend_object_get_class_name_t get_class_name; 
 zend_object_cast_t cast_object; 
 zend_object_count_elements_t count_elements; 
 zend_object_get_debug_info_t get_debug_info; 
 zend_object_get_closure_t get_closure; 
 zend_object_get_gc_t get_gc; 
 zend_object_do_operation_t do_operation; 
 zend_object_compare_t compare; 
 zend_object_get_properties_for_t get_properties_for; 
};
typedef struct _zend_llist_element {
 struct _zend_llist_element *next;
 struct _zend_llist_element *prev;
 char data[1]; 
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
typedef struct _zend_encoding zend_encoding;
typedef struct _zend_stack {
 int size, top, max;
 void *elements;
} zend_stack;
typedef struct _zend_vm_stack *zend_vm_stack;
typedef struct _zend_ini_entry zend_ini_entry;
typedef enum {
 ON_TOKEN,
 ON_FEEDBACK,
 ON_STOP
} zend_php_scanner_event;
struct _zend_vm_stack {
 zval *top;
 zval *end;
 zend_vm_stack prev;
};
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
 uint32_t param_count;
 HashTable *named_params;
} zend_fcall_info;
typedef struct _zend_fcall_info_cache {
 zend_function *function_handler;
 zend_class_entry *calling_scope;
 zend_class_entry *called_scope;
 zend_object *object;
} zend_fcall_info_cache;
typedef struct _zend_object_iterator zend_object_iterator;
typedef struct _zend_object_iterator_funcs {
 void (*dtor)(zend_object_iterator *iter);
 int (*valid)(zend_object_iterator *iter);
 zval *(*get_current_data)(zend_object_iterator *iter);
 void (*get_current_key)(zend_object_iterator *iter, zval *key);
 void (*move_forward)(zend_object_iterator *iter);
 void (*rewind)(zend_object_iterator *iter);
 void (*invalidate_current)(zend_object_iterator *iter);
 HashTable *(*get_gc)(zend_object_iterator *iter, zval **table, int *n);
} zend_object_iterator_funcs;
struct _zend_object_iterator {
 zend_object std;
 zval data;
 const zend_object_iterator_funcs *funcs;
 zend_ulong index; 
};
typedef struct _zend_class_iterator_funcs {
 zend_function *zf_new_iterator;
 zend_function *zf_valid;
 zend_function *zf_current;
 zend_function *zf_key;
 zend_function *zf_next;
 zend_function *zf_rewind;
} zend_class_iterator_funcs;
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
 zend_string *alias;
 uint32_t modifiers;
} zend_trait_alias;
typedef struct _zend_class_mutable_data {
 zval *default_properties_table;
 HashTable *constants_table;
 uint32_t ce_flags;
} zend_class_mutable_data;
typedef struct _zend_inheritance_cache_entry zend_inheritance_cache_entry;
struct _zend_class_entry {
 char type;
 zend_string *name;
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
 zval * * static_members_table__ptr;
 HashTable function_table;
 HashTable properties_info;
 HashTable constants_table;
 zend_class_mutable_data* * mutable_data__ptr;
 zend_inheritance_cache_entry *inheritance_cache;
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
 zend_function *__serialize;
 zend_function *__unserialize;
 zend_class_iterator_funcs *iterator_funcs_ptr;
 union {
  zend_object* (*create_object)(zend_class_entry *class_type);
  int (*interface_gets_implemented)(zend_class_entry *iface, zend_class_entry *class_type); 
 };
 zend_object_iterator *(*get_iterator)(zend_class_entry *ce, zval *object, int by_ref);
 zend_function *(*get_static_method)(zend_class_entry *ce, zend_string* method);
 int (*serialize)(zval *object, unsigned char **buffer, size_t *buf_len, zend_serialize_data *data);
 int (*unserialize)(zval *object, zend_class_entry *ce, const unsigned char *buf, size_t buf_len, zend_unserialize_data *data);
 uint32_t num_interfaces;
 uint32_t num_traits;
 union {
  zend_class_entry **interfaces;
  zend_class_name *interface_names;
 };
 zend_class_name *trait_names;
 zend_trait_alias **trait_aliases;
 zend_trait_precedence **trait_precedences;
 HashTable *attributes;
 uint32_t enum_backing_type;
 HashTable *backed_enum_table;
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
 zend_error_handling_t handling;
 zend_class_entry *exception;
} zend_error_handling;
typedef struct _zend_objects_store {
 zend_object **object_buckets;
 uint32_t top;
 uint32_t size;
 int free_list_head;
} zend_objects_store;
struct _zend_ini_entry {
 zend_string *name;
 int (*on_modify)(zend_ini_entry *entry, zend_string *new_value, void *mh_arg1, void *mh_arg2, void *mh_arg3, int stage);
 void *mh_arg1;
 void *mh_arg2;
 void *mh_arg3;
 zend_string *value;
 zend_string *orig_value;
 void (*displayer)(zend_ini_entry *ini_entry, int type);
 int module_number;
 uint8_t modifiable;
 uint8_t orig_modifiable;
 uint8_t modified;
};
typedef struct _zend_module_entry zend_module_entry;
typedef struct _zend_module_dep zend_module_dep;
typedef ZEND_RESULT_CODE zend_result;
typedef int ts_rsrc_id;
struct _zend_module_entry {
 unsigned short size;
 unsigned int zend_api;
 unsigned char zend_debug;
 unsigned char zts;
 const struct _zend_ini_entry *ini_entry;
 const struct _zend_module_dep *deps;
 const char *name;
 const struct _zend_function_entry *functions;
 zend_result (*module_startup_func)(int type, int module_number);
 zend_result (*module_shutdown_func)(int type, int module_number);
 zend_result (*request_startup_func)(int type, int module_number);
 zend_result (*request_shutdown_func)(int type, int module_number);
 void (*info_func)(zend_module_entry *zend_module);
 const char *version;
 size_t globals_size;
 ts_rsrc_id* globals_id_ptr;
 void (*globals_ctor)(void *global);
 void (*globals_dtor)(void *global);
 zend_result (*post_deactivate_func)(void);
 int module_started;
 unsigned char type;
 void *handle;
 int module_number;
 const char *build_id;
};
struct _zend_module_dep {
 const char *name; 
 const char *rel; 
 const char *version; 
 unsigned char type; 
};
typedef struct _zend_gc_status {
 uint32_t runs;
 uint32_t collected;
 uint32_t threshold;
 uint32_t num_roots;
} zend_gc_status;
typedef struct {
 zval *cur;
 zval *end;
 zval *start;
} zend_get_gc_buffer;
struct _zend_compiler_globals {
 zend_stack loop_var_stack;
 zend_class_entry *active_class_entry;
 zend_string *compiled_filename;
 int zend_lineno;
 zend_op_array *active_op_array;
 HashTable *function_table; 
 HashTable *class_table; 
 HashTable *auto_globals;
 zend_uchar parse_error;
 _Bool in_compilation;
 _Bool short_tags;
 _Bool unclean_shutdown;
 _Bool ini_parser_unbuffered_errors;
 zend_llist open_files;
 struct _zend_ini_parser_param *ini_parser_param;
 _Bool skip_shebang;
 _Bool increment_lineno;
 _Bool variable_width_locale; 
 _Bool ascii_compatible_locale; 
 zend_string *doc_comment;
 uint32_t extra_fn_flags;
 uint32_t compiler_options; 
 zend_oparray_context context;
 zend_file_context file_context;
 zend_arena *arena;
 HashTable interned_strings;
 const zend_encoding **script_encoding_list;
 size_t script_encoding_list_size;
 _Bool multibyte;
 _Bool detect_unicode;
 _Bool encoding_declared;
 zend_ast *ast;
 zend_arena *ast_arena;
 zend_stack delayed_oplines_stack;
 HashTable *memoized_exprs;
 int memoize_mode;
 void *map_ptr_real_base;
 void *map_ptr_base;
 size_t map_ptr_size;
 size_t map_ptr_last;
 HashTable *delayed_variance_obligations;
 HashTable *delayed_autoloads;
 HashTable *unlinked_uses;
 zend_class_entry *current_linking_class;
 uint32_t rtd_key_counter;
 zend_stack short_circuiting_opnums;
};
typedef void jmp_buf;
typedef struct _zend_fiber zend_fiber;
typedef struct _zend_fiber_context zend_fiber_context;
typedef struct _zend_error_info {
 int type;
 uint32_t lineno;
 zend_string *filename;
 zend_string *message;
} zend_error_info;
struct _zend_executor_globals {
 zval uninitialized_zval;
 zval error_zval;
 zend_array *symtable_cache[32];
 zend_array **symtable_cache_limit;
 zend_array **symtable_cache_ptr;
 zend_array symbol_table; 
 HashTable included_files; 
 jmp_buf *bailout;
 int error_reporting;
 int exit_status;
 HashTable *function_table; 
 HashTable *class_table; 
 HashTable *zend_constants; 
 zval *vm_stack_top;
 zval *vm_stack_end;
 zend_vm_stack vm_stack;
 size_t vm_stack_page_size;
 struct _zend_execute_data *current_execute_data;
 zend_class_entry *fake_scope; 
 uint32_t jit_trace_num; 
 zend_long precision;
 int ticks_count;
 uint32_t persistent_constants_count;
 uint32_t persistent_functions_count;
 uint32_t persistent_classes_count;
 HashTable *in_autoload;
 _Bool full_tables_cleanup;
 _Bool no_extensions;
 _Bool vm_interrupt;
 _Bool timed_out;
 zend_long hard_timeout;
 HashTable regular_list;
 HashTable persistent_list;
 int user_error_handler_error_reporting;
 zval user_error_handler;
 zval user_exception_handler;
 zend_stack user_error_handlers_error_reporting;
 zend_stack user_error_handlers;
 zend_stack user_exception_handlers;
 zend_error_handling_t error_handling;
 zend_class_entry *exception_class;
 zend_long timeout_seconds;
 int capture_warnings_during_sccp;
 HashTable *ini_directives;
 HashTable *modified_ini_directives;
 zend_ini_entry *error_reporting_ini_entry;
 zend_objects_store objects_store;
 zend_object *exception, *prev_exception;
 const zend_op *opline_before_exception;
 zend_op exception_op[3];
 struct _zend_module_entry *current_module;
 _Bool active;
 zend_uchar flags;
 zend_long assertions;
 uint32_t ht_iterators_count; 
 uint32_t ht_iterators_used; 
 HashTableIterator *ht_iterators;
 HashTableIterator ht_iterators_slots[16];
 void *saved_fpu_cw_ptr;
 zend_function trampoline;
 zend_op call_trampoline_op;
 HashTable weakrefs;
 _Bool exception_ignore_args;
 zend_long exception_string_param_max_len;
 zend_get_gc_buffer get_gc_buffer;
 zend_fiber_context *main_fiber_context;
 zend_fiber_context *current_fiber_context;
 zend_fiber *active_fiber;
 zend_long fiber_stack_size;
 _Bool record_errors;
 uint32_t num_errors;
 zend_error_info **errors;
 void *reserved[6];
};
typedef struct _zend_executor_globals zend_executor_globals;
typedef struct _IO_FILE FILE;
typedef size_t (*zend_stream_fsizer_t)(void* handle);
typedef ssize_t (*zend_stream_reader_t)(void* handle, char *buf, size_t len);
typedef void (*zend_stream_closer_t)(void* handle);
typedef enum {
 ZEND_HANDLE_FILENAME,
 ZEND_HANDLE_FP,
 ZEND_HANDLE_STREAM
} zend_stream_type;
typedef struct _zend_stream {
 void *handle;
 int isatty;
 zend_stream_reader_t reader;
 zend_stream_fsizer_t fsizer;
 zend_stream_closer_t closer;
} zend_stream;
typedef struct _zend_file_handle {
 union {
  FILE *fp;
  zend_stream stream;
 } handle;
 zend_string *filename;
 zend_string *opened_path;
 zend_uchar type; 
 _Bool primary_script;
 _Bool in_list; 
 char *buf;
 size_t len;
} zend_file_handle;
typedef struct _zend_ptr_stack {
 int top, max;
 void **elements;
 void **top_element;
 _Bool persistent;
} zend_ptr_stack;
typedef size_t (*zend_encoding_filter)(unsigned char **str, size_t *str_length, const unsigned char *buf, size_t length);
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
 zend_stack nest_location_stack; 
 zend_file_handle *in;
 uint32_t lineno;
 zend_string *filename;
 unsigned char *script_org;
 size_t script_org_size;
 unsigned char *script_filtered;
 size_t script_filtered_size;
 zend_encoding_filter input_filter;
 zend_encoding_filter output_filter;
 const zend_encoding *script_encoding;
 void (*on_event)(
  zend_php_scanner_event event, int token, int line,
  const char *text, size_t length, void *context);
 void *on_event_context;
 zend_ast *ast;
 zend_arena *ast_arena;
} zend_lex_state;
typedef struct _zend_heredoc_label {
 char *label;
 int length;
 int indentation;
 _Bool indentation_uses_spaces;
} zend_heredoc_label;
extern zend_ast_process_t zend_ast_process;
extern HashTable module_registry;
zend_result zend_hash_del(HashTable *ht, zend_string *key);
zval* zend_hash_find(const HashTable *ht, zend_string *key);
zval* zend_hash_add_or_update(HashTable *ht, zend_string *key, zval *pData, uint32_t flag);
int zend_set_user_opcode_handler(zend_uchar opcode, user_opcode_handler_t handler);
user_opcode_handler_t zend_get_user_opcode_handler(zend_uchar opcode);
void zend_do_inheritance_ex(zend_class_entry *ce, zend_class_entry *parent_ce, _Bool checked);
zend_object* zend_objects_new(zend_class_entry *ce);
void zend_object_std_init(zend_object *object, zend_class_entry *ce);
void object_properties_init(zend_object *object, zend_class_entry *class_type);
void zend_save_lexical_state(zend_lex_state *lex_state);
void zend_restore_lexical_state(zend_lex_state *lex_state);
void zend_prepare_string_for_scanning(zval *str, zend_string *filename);
zend_result zend_lex_tstring(zval *zv, unsigned char *ident);
int zendparse(void);
void zend_ast_destroy(zend_ast *ast);
zend_ast * zend_ast_create_list_0(zend_ast_kind kind);
zend_ast * zend_ast_list_add(zend_ast *list, zend_ast *op);
zend_ast * zend_ast_create_zval_ex(zval *zv, zend_ast_attr attr);
zend_ast * zend_ast_create_0(zend_ast_kind kind);
zend_ast * zend_ast_create_1(zend_ast_kind kind, zend_ast *child);
zend_ast * zend_ast_create_2(zend_ast_kind kind, zend_ast *child1, zend_ast *child2);
zend_ast * zend_ast_create_3(zend_ast_kind kind, zend_ast *child1, zend_ast *child2, zend_ast *child3);
zend_ast * zend_ast_create_4(zend_ast_kind kind, zend_ast *child1, zend_ast *child2, zend_ast *child3, zend_ast *child4);
zend_ast *zend_ast_create_decl(
 zend_ast_kind kind, uint32_t flags, uint32_t start_lineno, zend_string *doc_comment,
 zend_string *name, zend_ast *child0, zend_ast *child1, zend_ast *child2, zend_ast *child3, zend_ast *child4
);
zend_module_entry* zend_register_module_ex(zend_module_entry *module);
zend_result zend_startup_module_ex(zend_module_entry *module);
