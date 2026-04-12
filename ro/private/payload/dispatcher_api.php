<?php
namespace catlair;



/*
    Модуль полезной нагрузки проксирования запроса
*/



require_once LIB . '/web/web_payload.php';
require_once LIB . '/core/web_bot.php';



class DispatcherApi extends WebPayload
{
    const CONFIG = 'dispatcher.yaml';

    /**************************************************************************
        Методы API
    */

    /*
        Эндпоинт диспетчеризации
        Определеяетworker и перенаправляет запрос
        на выбранный worker путем проксирования
    */
    public function dispatch()
    {
        /* Начальный мониторинг */
        $this
        -> monBegin()
        /* Запись в мониторинг размера тушки запроса */
        -> add
        (
            [ 'total', 'requestSizeByte' ],
            clValueFromObject( $_SERVER, 'CONTENT_LENGTH' )
        );

        /* Определение конфига */
        $config = $this -> getApp() -> getParam([ 'payloads', 'dispatcher' ]);

        /* Опредление потенциальной полезной нагрузки */
        $payloadName = $this -> getUrl() -> getPath()[0] ?? '';

        /* Чтение потенциальных исполнителей для пэйловада */
        $workers = $this -> getWorkers( $payloadName );

        /* Опредленеие потенциального failback */
        $failback = false;

        if( empty( $workers ))
        {
            $failback = true;
            $payloadName = $config[ 'failback' ][ 'payload' ] ?? '';
            $workers = $this -> getWorkers( $payloadName );
        }

        if( empty( $workers ))
        {
            $this -> setResult
            (
                'worker-not-found',
                [
                    'payload-name' => $payloadName,
                    'method' => __METHOD__,
                    'line' => __LINE__
                ]
            );
        }
        else
        {
            $stats = $this -> selectSummonStats( $workers, $payloadName);

            /* Чтение количества попыток перенаправления */
            $maxTries = $this -> readMaxSummonTry();

            $attempt = 0;
            /* Цикл попыток исполнения */
            while( $attempt < $maxTries )
            {
                $attempt++;

                /* Получение worker */
                $worker = $this -> selectSummonHost( $stats );

                $this -> startSummonStat( $worker, $payloadName );

                if( $failback )
                {
//                    $this -> failbackCall();
                }
                else
                {
                    $this -> proxy( $worker );
                }

                $this -> stopSummonStat( $worker, $payloadName, $this -> getCode() );

                /*
                    Выходим из цикла
                        Если вызов успешен
                        Или превышение лимита subcall
                */
                if
                (
                    $this -> isOk()
                    || $this -> getCode() == 'error-config-subcall-limit'
                )
                {
                    break;
                }
            }
        }

        /* Завершающий мониторинг */
        $this
        -> monEnd()
        -> add([ 'result', $this -> getCode() ]);

        return $this;
    }



    /*
        Метод сохраняет состояние вызова и выполняет
        его делегирование на worker с передачей ссылки на состояние
    */
    public function deligate()
    {
        $this -> setResult( 'not-emplimented' );
        return $this;
    }



    /*
        Сбор пэйлоадов по известным исполнителям
        Подготавливает списки стэйтов для разраешения:
            workers = f( pauload )
    */
    public function collect_payloads()
    {
        $result = new Result();

        /* Временный массив: пэйлоад => [список воркеров] */
        $payloadMap = [];
        /* Извлекаем список воркеров из конфига */
        $hosts = $this -> readSummonHosts();

        foreach( $hosts as $url )
        {
            /* Запрос payload */
            $current = $this -> copy()
            -> summon( 'worker', 'get-payloads', [], [ $url ]);

            $result -> setDetail( $url, $current -> getResultAsArray() );

            if( $current -> isOk())
            {
                $workerPayloads = $current -> getDetails()[ 'routers' ] ?? [];
                /* Для каждого пэйлоада этого воркера добавляем его хэш */
                foreach( $workerPayloads as $payload )
                {
                    $payloadMap[ $payload ][] = $url;
                }
            }
        }

        $payloadsMap = [];

        /* Сохраняем каждый пэйлоад в отдельный файл */
        foreach( $payloadMap as $payload => $workers )
        {
            $uniqWorkers = array_unique( $workers );

            /* Сборка разрешений */
            $this -> setState
            (
                [ 'payloads', $payload ],
                json_encode( $uniqWorkers, JSON_UNESCAPED_SLASHES )
            );

            /* Сборка общего индекса */
            $payloadsMap[ $payload ] = $uniqWorkers;
        }

        /*
            Сохраняем общий файл для отладки
            Информация извлекается методом get_payloads
        */
        $this -> setState
        (
            [ 'payloads-map' ],
            json_encode
            (
                $payloadsMap,
                JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            )
        );

        $this -> resultFrom( $result );

        return $this -> resultToContent();
    }



    /*
        Enrpoint
        Возврат текущих состояний воркеров
        Информация собирается при вызовае метода collect_payloads
    */
    public function get_payloads()
    {
        return $this
        -> setDetail
        (
            'payloads_map',
            $this -> getPayloadsMap()
        )
        -> resultToContent();
    }



    /*
        Enrpoint
        Возваращает статистику по таблице маршрутов
    */
    public function build_stat()
    {
        $map = $this -> getPayloadsMap();
        $result = [];

        foreach ($map as $payload => $hosts)
        {
            $stats = $this->selectSummonStats($hosts, $payload);
            foreach ($stats as $host => $stat)
            {
                $result[$host][$payload] = $stat;
            }
        }
        return $this -> setDetail
        (
            'stat',
            $result
        )
        -> resultToContent();
    }



    /*
        Эндпоинт для очистки всей статистики
    */
    public function clear_stat()
    {
        $map = $this->getPayloadsMap();
        $deleted = 0;

        foreach( $map as $payload => $hosts )
        {
            foreach( $hosts as $host )
            {
                $this -> clearSummonStat( $host, $payload );
                $deleted++;
            }
        }

        return $this
        ->setDetail('deleted', $deleted)
        ->resultToContent();
    }


    /**************************************************************************
        Приватные методы
    */


    /*
        Return
    */
    private static function buildHash
    (
        string $a
    )
    :string
    {
        return hash( 'sha256', $a );
    }



    /*
        Возвращает список потенциальных воркеров для исполнения нагрузки
    */
    private function getWorkers
    (
        /* List of worker */
        string $aPayloadName
    )
    :array
    {
        $result = [];
        if( !empty( $aPayloadName ))
        {
            $result = json_decode
            (
                $this -> getState
                (
                    [ 'payloads', $aPayloadName ],
                    Engine::STATE_FILE,
                    '[]'
                ),
                true
            );
        }
        return $result;
    }



//    /*
//        Метод проксирования текущего запроса
//    */
//    private function proxy
//    (
//        string $aHost,
//        array $aConfig
//    )
//    {
//        /* Определение url */
//        $url = $aHost . $_SERVER[ 'REQUEST_URI' ];
//
//        /* Сборка заголовков */
//        $headers = $this -> getApp() -> getInHeaders();
//
//        /* Инициализация curl */
//        $ch = curl_init();
//
//        /* Сборка заголовков для curl */
//        $curlHeaders = [];
//        foreach( $headers as $name => $value )
//        {
//            $curlHeaders[] = "$name: $value";
//        }
//
//        /* Сборка тушки */
//        $postFields = $_POST;
//        if( !empty( $_FILES ))
//        {
//            /* Проксирование файлов */
//            foreach ($_FILES as $key => $file)
//            {
//                $postFields[ $key ] = new CURLFile
//                (
//                    $file['tmp_name'],
//                    $file['type'],
//                    $file['name']
//                );
//            }
//            curl_setopt( $ch, CURLOPT_POSTFIELDS, $postFields );
//        }
//        else
//        {
//            /* Проксирование тела запроса */
//            $method = $_SERVER[ 'REQUEST_METHOD' ];
//            if( $method !== 'GET' && $method !== 'HEAD' )
//            {
//                /* Передача сырого тела запроса */
//                curl_setopt( $ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
//            }
//        }
//
//        /* Установка параметров */
//        curl_setopt_array
//        (
//            $ch,
//            [
//                CURLOPT_ENCODING => true,
//                /* Вернуть ответ как строку */
//                CURLOPT_RETURNTRANSFER => true,
//                /* Включить заголовки в ответ */
//                CURLOPT_HEADER => true,
//                /* Не следовать редиректам */
//                CURLOPT_FOLLOWLOCATION => false,
//                /* Отключить проверку SSL сертификата */
//                CURLOPT_SSL_VERIFYPEER => false,
//                /* Отключить проверку имени хоста SSL */
//                CURLOPT_SSL_VERIFYHOST => false,
//                /* Использовать оригинальный HTTP метод */
//                CURLOPT_CUSTOMREQUEST => $_SERVER[ 'REQUEST_METHOD' ],
//                /* Передать оригинальные куки */
//                CURLOPT_COOKIE => $_SERVER[ 'HTTP_COOKIE' ] ?? '',
//                /* URL назначения */
//                CURLOPT_URL => $url,
//                /* Передать оригинальные заголовки */
//                CURLOPT_HTTPHEADER => $curlHeaders,
//                /* Общий таймаут 30 секунд */
//                CURLOPT_TIMEOUT => 30,
//                /* Таймаут соединения 5 секунд */
//                CURLOPT_CONNECTTIMEOUT => 5
//            ]
//        );
//
//        /*
//            Запрос CURL
//        */
//        $this -> getLog()
//        -> begin( 'Proxy execute' )
//        -> param( 'url', $url );
//        $response = curl_exec( $ch );
//        $this -> getLog() -> end();
//
//        /* Проверка результата запроса*/
//        if( $response === false )
//        {
//            /* Отказ вызова */
//            $this -> setResult
//            (
//                'proxy-call-error',
//                [
//                    'code' => curl_error( $ch ),
//                    'message' => curl_error( $ch )
//                ]
//            );
//        }
//        else
//        {
//            /* Запрос выполнен */
//            /* Извлеченеи заголовков из ответа */
//            $headerSize = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
//            $this -> setContent( substr( $response, $headerSize ));
//            $rawHeaders = substr( $response, 0, $headerSize );
//
//            /* Преобразование строки заголовков в массив ключ => значение */
//            $headerLines = explode("\r\n", trim($rawHeaders));
//            $headers = [];
//
//            foreach( $headerLines as $line )
//            {
//                // Пропускаем пустые строки и строку статуса HTTP
//                if ( !empty($line) ?? strpos( $line, 'HTTP/' ) !== 0)
//                {
//                    $parts = explode( ':', $line, 2 );
//                    if( count($parts) === 2 )
//                    {
//                        $name = trim( $parts[ 0 ]);
//                        $value = trim( $parts[ 1 ]);
//                        $headers[ $name ] = $value;
//                    }
//                }
//            }
//
//            /* Выгрузка заголовков итоговых */
//            $this -> getApp() -> applyHeaders( $headers );
//        }
//        curl_close( $ch );
//
//
//        return $this;
//    }



    public function proxy
    (
        string $targetUrl
    ): self
    {
        $headers = $this -> getApp() -> getInHeaders();

        /* Формируем запрос через WebBot */
        $bot = WebBot::create( $this -> getLog() )
        -> setUrl( Url::create() -> parse( $targetUrl . $_SERVER[ 'REQUEST_URI' ]))
        -> setHeaders( $headers )
        -> setRequestTimeoutMls( $this -> readSummonRequestTimeout());

        $method = $_SERVER[ 'REQUEST_METHOD' ];
        switch( $method )
        {
            case 'POST':
                $bot -> setPostParams( $_POST );
                if( !empty( $_FILES ))
                {
                    $bot -> setPostParams
                    (
                        array_merge
                        (
                            $_POST,
                            $this -> prepareFiles( $_FILES )
                        )
                    );
                }
            break;
            case 'PUT':
            case 'PATCH':
                $bot -> setPostParams( file_get_contents( 'php://input' ));
            break;
        }

        $bot -> execute() -> resultTo( $this );

        $this -> setContent( $bot -> getContent() );
        $this -> getApp() -> applyHeaders( $bot -> getHeaders() );

        return $this;
    }



    private function prepareFiles
    (
        array $files
    )
    : array
    {
        $result = [];
        foreach( $files as $key => $file )
        {
            $result[ $key ] = new CURLFile
            (
                $file['tmp_name'],
                $file['type'],
                $file['name']
            );
        }
        return $result;
    }



    /*
        Возвращает список доступных пэйлоадов
    */
    private function getPayloadsMap()
    :array
    {
        return json_decode
        (
            $this -> getState( [ 'payloads-map' ], Engine::STATE_FILE, '{}' ),
            true
        );
    }
}
