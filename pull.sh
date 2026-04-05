#!/bin/bash
# Repsitory update on the current branch


# Переход в родительскую директорию
cd "$(dirname "$0")/."

echo -e "\033[0;32m$(pwd)\033[0m"
git pull --rebase && git push origin HEAD && \

echo '{ "code":"ok", "task":"pull" }'
