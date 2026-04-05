#!/bin/bash
# Меняем группу
sudo chown -R :www-data ./

# 1. Сначала убираем исполнение со всех файлов (кроме папок)
sudo chmod -R a-x ./

# 2. Потом даем папкам правильные права с исполнением
sudo chmod -R u=rwX,go=rX ./

# 3. Папка ./rw - даем группе запись
sudo chmod -R g+w ./rw

# 4. Возвращаем исполнение нужным скриптам
chmod +x pull.sh push.sh prepare.sh
