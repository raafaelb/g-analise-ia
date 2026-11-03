#!/bin/sh
set -eu
umask 027
for directory in storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
    mkdir -p "$directory"
    if [ ! -w "$directory" ]; then
        printf 'O usuario do servico precisa escrever em %s. Confira o volume e sua propriedade.\n' "$directory" >&2
        exit 1
    fi
done
exec "$@"
