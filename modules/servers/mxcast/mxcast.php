<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function mxcast_MetaData()
{
    return [
        "DisplayName" => "MxCast",
        "APIVersion" => "1.0.0"
    ];
}

function mxcast_configoptions()
{
    $configarray = [
        'listeners' => [
            'FriendlyName' => 'Ouvintes',
            'Type' => 'text',
            'Size' => '10',
            'Description' => '<br>(Número máximo de ouvintes simultâneos)'
        ],
        'bitrate' => [
            'FriendlyName' => 'Bitrate',
            'Type' => 'dropdown',
            'Options' => '24,32,48,64,96,128,256,320',
            'Description' => '<br>(Taxa de transmissão em kbps. Verifique o limite do seu plano)'
        ],
        'disk' => [
            'FriendlyName' => 'Espaço AutoDJ',
            'Type' => 'text',
            'Size' => '10',
            'Description' => '<br>(Espaço reservado para o AutoDJ em MB. 0 desativa o AutoDJ)'
        ],
        'permPP' => [
            'FriendlyName' => 'Programas e Programetes',
            'Type' => 'yesno',
            'Description' => '(Permitir recurso de Programas e Programetes)'
        ],
        'permPPdownload' => [
            'FriendlyName' => 'Download de Conteúdo',
            'Type' => 'yesno',
            'Description' => '(Permitir download de conteúdo de Programas e Programetes)'
        ],
        'permMusicBank' => [
            'FriendlyName' => 'Banco de Músicas',
            'Type' => 'yesno',
            'Description' => '(Permitir acesso ao Banco de Músicas)'
        ],
        'permApps' => [
            'FriendlyName' => 'Central de Aplicativos',
            'Type' => 'yesno',
            'Description' => '(Permitir acesso à Central de Aplicativos)'
        ],
        'permSocialLive' => [
            'FriendlyName' => 'Lives',
            'Type' => 'yesno',
            'Description' => '(Permitir transmissões ao vivo no YouTube, Facebook, etc)'
        ],
        'permCamStudio' => [
            'FriendlyName' => 'Câmera Studio',
            'Type' => 'yesno',
            'Description' => '(Permitir recurso Câmera Studio)'
        ]
    ];

    return $configarray;
}

function mxcast_adminlink($params)
{
    return '<a href="https://' . $params['serverhostname'] . '/admin" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-default">Acessar Painel</a>';
}

function mxcast_createaccount($params)
{
    // global $debug;
    try {

        // Verifica se já há host:porta no domínio
        if (!empty($params['domain'])) {
            $parts = explode(':', $params['domain']);
            $hasPort = (count($parts) === 2 && !empty($parts[1]));
            if ($hasPort) {
                return 'Este streaming já está criado.';
            }
        }

        $password = substr(md5(time() . rand()), 0, 12);

        // Chamada à API do MXCast
        $response = mxcast_request('POST', '/reseller/streams', [
            'Authorization' => $params['serveraccesshash'],
            'json' => [
                'max_listeners' => (int) $params['configoption1'], // 999999 para ilimitado (required)
                'max_bitrate' => (int) $params['configoption2'], // bitrate do streaming (required)
                'password' => $password,  // senha de acesso do painel (required)
                'disk_space' => (int) $params['configoption3'], // 0 para desabilitar autodj (optional)
                'perm_pp_access' => ($params['configoption4'] === 'on'),
                'perm_pp_dl' => ($params['configoption5'] === 'on'),
                'perm_music_bank' => ($params['configoption6'] === 'on'),
                'perm_apps' => ($params['configoption7'] === 'on'),
                'perm_social_live' => ($params['configoption8'] === 'on'),
                'perm_cam_studio' => ($params['configoption9'] === 'on'),
                'identification' => $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'],
            ]
        ]);

        // Validar resposta da API
        if (!isset($response['success']) || $response['success'] !== true || empty($response['data'])) {
            $message = isset($response['message']) ? $response['message'] : 'Erro ao criar conta: resposta inválida da API MxCast.';
            return $message;
        }

        $port = $response['data']['port'];
        $server = $response['data']['server'];
        $domain = "{$server}:{$port}";

        // Atualizar dados do serviço no WHMCS
        mxcast_update([
            'table' => 'tblhosting',
            'condition' => [
                "id" => $params['accountid']
            ],
            'data' => [
                'username' => $port,
                'password' => encrypt($password),
                'domain' => $domain,
                'dedicatedip' => $server
            ]
        ]);
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function mxcast_suspendaccount($params)
{
    //global $debug;
    try {

        // Verifica se o domínio existe
        if (empty($params['domain'])) {
            return 'O streaming não está cadastrado.';
        }

        // Verifica se já há host:porta no domínio
        $parts = explode(':', $params['domain']);
        $hasPort = (count($parts) === 2 && !empty($parts[1]));
        if (!$hasPort) {
            return 'O streaming não está cadastrado.';
        }

        // Verifica o nome de usuário (porta)
        if (empty($params['username'])) {
            return 'O campo Nome de Usuário (porta) está vazio.';
        }

        if (!is_numeric($params['username'])) {
            return 'O campo Nome de Usuário (porta) é inválido.';
        }

        $port = (int) $params['username'];

        // Chamada à API do MXCast
        $response = mxcast_request('POST', "/reseller/streams/{$port}/suspend", [
            'Authorization' => $params['serveraccesshash']
        ]);

        // Validar resposta da API
        if (!isset($response['success']) || $response['success'] !== true) {
            $message = isset($response['message']) ? $response['message'] : 'Erro ao suspender conta: resposta inválida da API MxCast.';
            return $message;
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function mxcast_unsuspendaccount($params)
{

    // global $debug;
    try {

        // Verifica se o domínio existe
        if (empty($params['domain'])) {
            return 'O streaming não está cadastrado.';
        }

        // Verifica se já há host:porta no domínio
        $parts = explode(':', $params['domain']);
        $hasPort = (count($parts) === 2 && !empty($parts[1]));
        if (!$hasPort) {
            return 'O streaming não está cadastrado.';
        }

        // Verifica o nome de usuário (porta)
        if (empty($params['username'])) {
            return 'O campo Nome de Usuário (porta) está vazio.';
        }

        if (!is_numeric($params['username'])) {
            return 'O campo Nome de Usuário (porta) é inválido.';
        }

        $port = (int) $params['username'];

        // Chamada à API do MXCast
        $response = mxcast_request('POST', "/reseller/streams/{$port}/unsuspend", [
            'Authorization' => $params['serveraccesshash']
        ]);

        // Validar resposta da API
        if (!isset($response['success']) || $response['success'] !== true) {
            $message = isset($response['message']) ? $response['message'] : 'Erro ao cancelar a suspensão da conta: resposta inválida da API MxCast.';
            return $message;
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function mxcast_terminateaccount($params)
{

    // global $debug;
    try {

        // Verifica se o domínio existe
        if (empty($params['domain'])) {
            return 'O streaming não está cadastrado.';
        }

        // Verifica se já há host:porta no domínio
        $parts = explode(':', $params['domain']);
        $hasPort = (count($parts) === 2 && !empty($parts[1]));
        if (!$hasPort) {
            return 'O streaming não está cadastrado.';
        }

        // Verifica o nome de usuário (porta)
        if (empty($params['username'])) {
            return 'O campo Nome de Usuário (porta) está vazio.';
        }

        if (!is_numeric($params['username'])) {
            return 'O campo Nome de Usuário (porta) é inválido.';
        }

        $port = (int) $params['username'];

        // Chamada à API do MXCast
        $response = mxcast_request('DELETE', "/reseller/streams/{$port}", [
            'Authorization' => $params['serveraccesshash']
        ]);

        // Validar resposta da API
        if (!isset($response['success']) || $response['success'] !== true) {
            $message = isset($response['message']) ? $response['message'] : 'Erro ao excluir a conta: resposta inválida da API MxCast.';
            return $message;
        }

        // Limpa os dados do serviço no WHMCS
        mxcast_update([
            'table' => 'tblhosting',
            'condition' => [
                "id" => $params['accountid']
            ],
            'data' => [
                'username' => '',
                'password' => '',
                'domain' => '',
                'dedicatedip' => ''
            ]
        ]);
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}


/**
 * ===========================
 * FUNÇÕES PERSONALIZADAS MXCAST
 * ===========================
 */

function mxcast_request($method = 'GET', $service, $options = [])
{
    $url = 'https://api.mxcast.com.br' . $service;
    $ch = curl_init();

    $method = strtoupper($method);
    $headers = isset($options['headers']) ? $options['headers'] : [];
    $body = isset($options['json']) ? $options['json'] : (isset($options['form_params']) ? $options['form_params'] : null);
    $token = isset($options['Authorization']) ? $options['Authorization'] : null;

    // GET com query string
    if ($method === 'GET' && !empty($body)) {
        $url .= '?' . http_build_query($body);
        $body = null;
    }

    // Define método HTTP
    switch ($method) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            break;
        case 'PUT':
        case 'PATCH':
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            break;
    }

    // Corpo da requisição
    if (!empty($body)) {
        if (isset($options['json'])) {
            $bodyData = json_encode($body);
            $headers['Content-Type'] = 'application/json';
        } else {
            $bodyData = http_build_query($body);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyData);
    }

    // Autorização
    if (!empty($token)) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    // Formata headers
    $formattedHeaders = [];
    foreach ($headers as $key => $value) {
        $formattedHeaders[] = $key . ': ' . $value;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false, // use true em produção
        CURLOPT_HTTPHEADER => $formattedHeaders,
        CURLOPT_USERAGENT => 'WHMCS MxCast (' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . ')',
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'message' => 'Não foi possível se conectar à API MxCast.' . $error];
    }

    curl_close($ch);

    $result = json_decode($response, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        return $result;
    }

    return [
        'success' => false,
        'message' => 'JSON Error: ' . json_last_error_msg()
    ];
}

function mxcast_update($params)
{
    $table = isset($params['table']) ? $params['table'] : null;
    $condition = isset($params['condition']) ? $params['condition'] : [];
    $data = isset($params['data']) ? $params['data'] : [];

    if (!$table || empty($condition) || empty($data)) {
        throw new InvalidArgumentException("Tabela, condição ou dados de atualização inválidos");
    }

    try {
        $affected = Capsule::table($table)->where($condition)->update($data);
        return $affected > 0;
    } catch (\Illuminate\Database\QueryException $e) {
        throw new RuntimeException("Erro ao atualizar dados: " . $e->getMessage());
    } catch (Exception $e) {
        throw new RuntimeException("Erro inesperado: " . $e->getMessage());
    }
}
