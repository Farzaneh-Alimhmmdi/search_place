#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
php tests/divar_contact_persistence.php
for scenario in search saved new_schema db_failure schema_failure no_phone unauthenticated expired_auth captcha_blocked rate_limited server_error invalid_response transport_error unknown_ad missing_id malformed_id; do
    php tests/divar_phone_route.php "$scenario"
done
