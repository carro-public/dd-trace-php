#include "priority_sampling.h"

#include <mt19937-64.h>

#include <ext/pcre/php_pcre.h>

#include "../compat_string.h"
#include "../configuration.h"

#include "../limiter/limiter.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

enum dd_sampling_mechanism {
    DD_MECHANISM_AGENT_RATE = 1,
    DD_MECHANISM_REMOTE_RATE = 2,
    DD_MECHANISM_RULE = 3,
    DD_MECHANISM_MANUAL = 4,
};

static void dd_update_decision_maker_tag(ddtrace_span_data *span, enum dd_sampling_mechanism mechanism) {
    zend_array *meta = ddtrace_spandata_property_meta(span);

    zend_long sampling_priority = ddtrace_fetch_prioritySampling_from_span(span->root);
    if (DDTRACE_G(propagated_priority_sampling) == sampling_priority) {
        return;
    }

    if (sampling_priority > 0 && sampling_priority != DDTRACE_PRIORITY_SAMPLING_UNSET) {
        if (!zend_hash_str_exists(meta, "_dd.p.dm", sizeof("_dd.p.dm") - 1)) {
            zval dm;
            ZVAL_STR(&dm, zend_strpprintf(0, "-%d", mechanism));
            zend_hash_str_add_new(meta, "_dd.p.dm", sizeof("_dd.p.dm") - 1, &dm);
        }
    } else {
        zend_hash_str_del(meta, "_dd.p.dm", sizeof("_dd.p.dm") - 1);
    }
}

static bool dd_rule_matches(zval *pattern, zval *prop) {
    if (Z_TYPE_P(pattern) != IS_STRING) {
        return false;
    }
    if (Z_TYPE_P(prop) != IS_STRING) {
        return true;  // default case unset or null must be true, everything else is too then...
    }

    zend_string *regex = zend_strpprintf(0, "(%s)", Z_STRVAL_P(pattern));
    pcre_cache_entry *pce = pcre_get_compiled_regex_cache(regex);
    zval ret;
#if PHP_VERSION_ID < 70400
    php_pcre_match_impl(pce, Z_STRVAL_P(prop), (int)Z_STRLEN_P(prop), &ret, NULL, 0, 0, 0, 0);
#else
    php_pcre_match_impl(pce, Z_STR_P(prop), &ret, NULL, 0, 0, 0, 0);
#endif
    zend_string_release(regex);
    return Z_TYPE(ret) == IS_LONG && Z_LVAL(ret) > 0;
}

static void dd_decide_on_sampling(ddtrace_span_data *span) {
    int priority = DDTRACE_G(default_priority_sampling);
    // manual if it's not just inherited, otherwise this value is irrelevant (as sampling priority will be default)
    enum dd_sampling_mechanism mechanism = DD_MECHANISM_MANUAL;
    if (priority == DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
        zval *rule;
        bool explicit_rule = zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_SAMPLE_RATE].name_index >= 0;
        double default_sample_rate = get_DD_TRACE_SAMPLE_RATE(), sample_rate = default_sample_rate;

        ZEND_HASH_FOREACH_VAL(get_DD_TRACE_SAMPLING_RULES(), rule) {
            if (Z_TYPE_P(rule) != IS_ARRAY) {
                continue;
            }

            bool rule_matches = true;

            zval *rule_pattern;
            if ((rule_pattern = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("service")))) {
                rule_matches &= dd_rule_matches(rule_pattern, ddtrace_spandata_property_service(span));
            }
            if ((rule_pattern = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("name")))) {
                rule_matches &= dd_rule_matches(rule_pattern, ddtrace_spandata_property_name(span));
            }

            zval *sample_rate_zv;
            if (rule_matches && (sample_rate_zv = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("sample_rate")))) {
                sample_rate = zval_get_double(sample_rate_zv);
                explicit_rule = true;
                break;
            }
        }
        ZEND_HASH_FOREACH_END();

        bool sampling = (double)genrand64_int64() < sample_rate * (double)~0ULL;
        bool limited  = ddtrace_limiter_active() && (sampling && !ddtrace_limiter_allow());

        if (explicit_rule) {
            mechanism = DD_MECHANISM_RULE;
            priority = sampling && !limited ? PRIORITY_SAMPLING_USER_KEEP : PRIORITY_SAMPLING_USER_REJECT;
        } else {
            mechanism = DD_MECHANISM_AGENT_RATE;
            priority = sampling && !limited ? PRIORITY_SAMPLING_AUTO_KEEP : PRIORITY_SAMPLING_AUTO_REJECT;
        }

        zval sample_rate_zv;
        ZVAL_DOUBLE(&sample_rate_zv, sample_rate);
        zend_hash_str_update(ddtrace_spandata_property_metrics(span), ZEND_STRL("_dd.rule_psr"),
                             &sample_rate_zv);

        if (limited) {
            zval limit_zv;
            ZVAL_DOUBLE(&limit_zv, ddtrace_limiter_rate());
            zend_hash_str_update(ddtrace_spandata_property_metrics(span), ZEND_STRL("_dd.limit_psr"),
                            &limit_zv);
        }
    }

    zval priority_zv;
    ZVAL_LONG(&priority_zv, priority);
    zend_hash_str_update(ddtrace_spandata_property_metrics(span), ZEND_STRL("_sampling_priority_v1"),
                         &priority_zv);

    dd_update_decision_maker_tag(span, mechanism);
}

zend_long ddtrace_fetch_prioritySampling_from_root(void) {
    if (!DDTRACE_G(active_stack)->root_span) {
        if (DDTRACE_G(default_priority_sampling) == DDTRACE_PRIORITY_SAMPLING_UNSET) {
            return DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
        }
        return DDTRACE_G(default_priority_sampling);
    }

    return ddtrace_fetch_prioritySampling_from_span(DDTRACE_G(active_stack)->root_span);
}

zend_long ddtrace_fetch_prioritySampling_from_span(ddtrace_span_data *root_span) {
    zval *priority_zv;
    zend_array *root_metrics = ddtrace_spandata_property_metrics(root_span);
    if (!(priority_zv = zend_hash_str_find(root_metrics, ZEND_STRL("_sampling_priority_v1")))) {
        if (DDTRACE_G(default_priority_sampling) == DDTRACE_PRIORITY_SAMPLING_UNSET) {
            return DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
        }

        dd_decide_on_sampling(root_span);
        priority_zv = zend_hash_str_find(root_metrics, ZEND_STRL("_sampling_priority_v1"));
    }

    return zval_get_long(priority_zv);
}

void ddtrace_set_prioritySampling_on_root(zend_long priority) {
    ddtrace_span_data *root_span = DDTRACE_G(active_stack)->root_span;

    if (!root_span) {
        return;
    }

    zend_array *root_metrics = ddtrace_spandata_property_metrics(root_span);
    if (priority == DDTRACE_PRIORITY_SAMPLING_UNKNOWN || priority == DDTRACE_PRIORITY_SAMPLING_UNSET) {
        zend_hash_str_del(root_metrics, ZEND_STRL("_sampling_priority_v1"));
    } else {
        zval zv;
        ZVAL_LONG(&zv, priority);
        zend_hash_str_update(root_metrics, ZEND_STRL("_sampling_priority_v1"), &zv);

        dd_update_decision_maker_tag(root_span, DD_MECHANISM_MANUAL);
    }
}
