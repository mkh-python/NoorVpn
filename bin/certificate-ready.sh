#!/usr/bin/env bash
# Silent readiness probe for reuse of an existing domain certificate.
set -euo pipefail
[[ $# == 3 && -r $1 && -r $2 ]] || exit 1
certificate=$1; private_key=$2; domain=$3
openssl x509 -in "$certificate" -noout -checkend 86400 >/dev/null 2>&1 || exit 1
openssl x509 -in "$certificate" -noout -checkhost "$domain" 2>/dev/null | grep -Fq 'does match certificate' || exit 1
certificate_key=$(openssl x509 -in "$certificate" -pubkey -noout 2>/dev/null | openssl pkey -pubin -outform DER 2>/dev/null | sha256sum)
private_public_key=$(openssl pkey -in "$private_key" -passin pass: -pubout -outform DER 2>/dev/null | sha256sum)
[[ $certificate_key == "$private_public_key" ]]
