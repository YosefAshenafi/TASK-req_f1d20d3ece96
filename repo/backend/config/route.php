<?php
return [
    'url_domain_root'        => '',
    'url_common_param'       => false,
    'url_param_type'         => 0,
    // Require the full path to match a rule. With this off, "activities/:id"
    // also matched "activities/:id/tasks" (trailing segment ignored), shadowing
    // every sub-resource route (/tasks, /versions, /signups, /sensitive, ...).
    'route_complete_match'   => true,
    'route_check_cache'      => false,
    'url_route_must'         => false,
    'url_html_suffix'        => '',
    'url_force_domain'       => '',
];
