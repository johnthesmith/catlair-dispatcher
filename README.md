# Dispatcher

* [Описание](#описание)
* [Конфигурация php-fpm](#конфигурация-php-fpm)
* [Конфигурация ядра](#конфигурация-ядра)



# Описание

1. Демонстрационный сервис диспетчеризации вызовов.
0. Клиентский запрос проксируется на один из доступных исполнителей.
0. При выборе исполнителя учитывается его функционал и статистика доступности.




# Конфигурация php-fpm

SIAMA требует настройки количества потоков php-fpm исходя из нагрузки.

## Расчет количества php-fpm

`P` - количество php-fpm на инстанс;
`C` - количество одновременных запросов;
`D` - максимальная глубина вызова полезных нагрузок определяется на основе 
реализации бизнеспроцессов.

### Расчет количество php-fpm монолит

```
P = C × (1 + D × 2)
```

### Расчет количество php-fpm разеленная схема

```
P = C × D
```


## Конфигурация php-fpm

1. Перед установкой `P` рассчитайте потребление RAM на один процесс и
убедитесь что на оборудовании достаточно памяти на запуск максимального
количество php-fpm.

```
sudo nano /etc/php/8.3/fpm/pool.d/www.conf
```

1. Вариант динамического распредлеения, при этом `Kmin` и `Kmax` определяются 
исходя из специфики задачи и требований экономии ресурсов.

```
pm = dynamic
pm.start_servers = <P>
pm.min_spare_servers = <P*Kmin>
pm.max_spare_servers = <P*Kmax>
```

2. Вариант статического распредлеения, исходит из строгого лимита количеств 
php-fpm `P`.

```
pm = static
pm.max_children = <P>
```


# Тестирование

```
ab -n 100000 -c 100 http://dispatcher:42002/demo/proc
```


# Мониторинг

```
watch -d -n 0.1 "ps aux | grep php-fpm | wc -l"
```

```
watch -d -n 0.1 "netstat -tlpan | grep TIME_WAIT | wc -l"
```



# Конфигурация ядра

1. Решени активно использует соединения. Для исключениея накопления `TIME_WAIT` 
необходим тюнинг ядра.

```
sudo nano /etc/sysctl.conf
```

```
# Максимальное количество TIME_WAIT после чего они закрываются
net.ipv4.tcp_max_tw_buckets = 5000

# Максимальный размер таблицы трэкинга состояния TCP соединений <- критично для 
# схемы При незком значени TIME_WAIT накапливаются до определнного объема и 
# происходит отказ в ошибку в /etc/log/syslog при переполнении Feb 17 19:06:08 
# dev-arch01 kernel: [5907220.780913] nf_conntrack: nf_conntrack: table full, 
# dropping packet
net.netfilter.nf_conntrack_max = 120000

# Increase size of file handles and inode cache
fs.file-max = 2097152

# Do less swapping
vm.swappiness = 10
vm.dirty_ratio = 60
vm.dirty_background_ratio = 2

# Group tasks by TTY
#kernel.sched_autogroup_enabled = 0

### GENERAL NETWORK SECURITY OPTIONS ###

# Number of times SYNACKs for passive TCP connection.
net.ipv4.tcp_synack_retries = 2

# Protect Against TCP Time-Wait
net.ipv4.tcp_rfc1337 = 1

# Control Syncookies
net.ipv4.tcp_syncookies = 1

# Decrease the time default value for tcp_fin_timeout connection
net.ipv4.tcp_fin_timeout = 15

# Decrease the time default value for connections to keep alive
net.ipv4.tcp_keepalive_time = 300
net.ipv4.tcp_keepalive_probes = 5
net.ipv4.tcp_keepalive_intvl = 15

### TUNING NETWORK PERFORMANCE ###

# Default Socket Receive Buffer
net.core.rmem_default = 31457280

# Maximum Socket Receive Buffer
net.core.rmem_max = 33554432

# Default Socket Send Buffer
net.core.wmem_default = 31457280

# Maximum Socket Send Buffer
net.core.wmem_max = 33554432

# Increase number of incoming connections
net.core.somaxconn = 655350

# Increase number of incoming connections backlog
net.core.netdev_max_backlog = 65536

# Increase the maximum amount of option memory buffers
net.core.optmem_max = 25165824

# Increase the maximum total buffer-space allocatable
# This is measured in units of pages (4096 bytes)
net.ipv4.tcp_mem = 786432 1048576 26777216
net.ipv4.udp_mem = 65536 131072 262144

# Increase the read-buffer space allocatable
net.ipv4.tcp_rmem = 8192 87380 33554432
net.ipv4.udp_rmem_min = 16384

# Increase the write-buffer-space allocatable
net.ipv4.tcp_wmem = 8192 65536 33554432
net.ipv4.udp_wmem_min = 16384

net.ipv4.tcp_tw_reuse = 1
```






B----D
     |
     D----W
          |
          W----D
               |
               D----W
                    |
               D....W
               |
               D----W
                    |
               D....W
               |
          W....D
          |
     D....W
     |
B....D
