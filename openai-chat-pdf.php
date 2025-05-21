<?php
/*
Description: Fichero con la lógica para interactuar con OpenAI: subida y procesamiento de ficheros para RAG, instrucciones y selección del modelo y creación de preguntas y respuestas.
*/

// Asegúrate de definir OPENAI_API_KEY en wp-config.php
if (!defined('OPENAI_API_KEY')) return;

// Escanea los PDF en uploads y sube los nuevos a OpenAI
function subir_pdfs_y_obtener_ids_v2() {
    $pdfs = obtener_rutas_pdfs_wp();
    $subidos = get_option('openai_archivos_subidos', []);
    $file_ids = [];

    foreach ($pdfs as $pdf) {
        $hash = md5($pdf);
        if (isset($subidos[$hash])) {
            $file_ids[] = $subidos[$hash];
            continue;
        }

        $file_id = subir_pdf_a_openai($pdf);
        if ($file_id) {
            $subidos[$hash] = $file_id;
            $file_ids[] = $file_id;
        }
    }

    update_option('openai_archivos_subidos', $subidos);
    return $file_ids;
}

// Crear vector store si no existe
function crear_vector_store($file_ids) {
    $vector_store_id = get_option('openai_vector_store_id');
    if ($vector_store_id) return $vector_store_id;

    $response = wp_remote_post('https://api.openai.com/v1/vector_stores', [
        'headers' => [
            'Authorization' => 'Bearer ' . OPENAI_API_KEY,
            'Content-Type'  => 'application/json',
            'OpenAI-Beta'   => 'assistants=v2'
        ],
        'body' => json_encode([
            'file_ids' => $file_ids,
            'name' => 'Lengua Sexto Primaria'
        ])
    ]);

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['id'])) {
        update_option('openai_vector_store_id', $body['id']);
        return $body['id'];
    }

    error_log('[OPENAI vector store error] ' . print_r($body, true));
    return null;
}

function obtener_o_crear_asistente_openai_v2() {
    $asistente_id = get_option('openai_asistente_lengua');
    if ($asistente_id) return $asistente_id;

    $data = [
        "instructions" => "Eres Miguel, un profe de lengua muy majo que ayuda a niños de sexto de primaria. Explica de forma clara y divertida, con ejemplos fáciles de entender. Usa un lenguaje cercano, sin palabras difíciles, como si hablaras directamente con un alumno. Dirígete a él en singular. Evita por completo cualquier contenido que no sea apropiado para niños de esa edad. Sé amable, simpático y un poco bromista si hace falta para que aprender sea más divertido. No respondas preguntas que incluyan temas inapropiados para niños de sexto de primaria (como violencia, contenido sexual, drogas, lenguaje ofensivo, etc). Si recibes algo así, responde de forma amable que no puedes hablar de ese tema sin dar ninguna explicación.",
        "model" => "gpt-4",
        "tools" => [["type" => "file_search"]]
    ];

    $response = wp_remote_post('https://api.openai.com/v1/assistants', [
        'headers' => [
            'Authorization' => 'Bearer ' . OPENAI_API_KEY,
            'Content-Type'  => 'application/json',
            'OpenAI-Beta'   => 'assistants=v2',
        ],
        'body' => json_encode($data)
    ]);

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['error'])) {
        error_log('[OpenAI Assistant ERROR] ' . print_r($body['error'], true));
    }

    $asistente_id = $body['id'] ?? null;
    if ($asistente_id) {
        update_option('openai_asistente_lengua', $asistente_id);
    }

    return $asistente_id;
}

function obtener_respuesta_desde_asistente($mensaje_usuario) {
    $asistente_id = obtener_o_crear_asistente_openai_v2();
    $file_ids = subir_pdfs_y_obtener_ids_v2();
    $vector_store_id = crear_vector_store($file_ids);

    if (!$asistente_id || !$vector_store_id) return 'Error inicializando asistente o vector store';

    // Crear hilo
    $response = wp_remote_post('https://api.openai.com/v1/threads', [
        'headers' => [
            'Authorization' => 'Bearer ' . OPENAI_API_KEY,
            'Content-Type'  => 'application/json',
            'OpenAI-Beta'   => 'assistants=v2',
        ],
        'body' => '{}'
    ]);

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $thread_id = $body['id'] ?? null;
    if (!$thread_id) return 'No se pudo crear el hilo.';

    // Agregar mensaje
    wp_remote_post("https://api.openai.com/v1/threads/$thread_id/messages", [
        'headers' => [
            'Authorization' => 'Bearer ' . OPENAI_API_KEY,
            'Content-Type'  => 'application/json',
            'OpenAI-Beta'   => 'assistants=v2',
        ],
        'body' => json_encode([
            "role" => "user",
            "content" => $mensaje_usuario
        ])
    ]);

    // Ejecutar asistente
    $run_response = wp_remote_post("https://api.openai.com/v1/threads/$thread_id/runs", [
        'headers' => [
            'Authorization' => 'Bearer ' . OPENAI_API_KEY,
            'Content-Type'  => 'application/json',
            'OpenAI-Beta'   => 'assistants=v2',
        ],
        'body' => json_encode([
            "assistant_id" => $asistente_id,
            "tools" => [["type" => "file_search"]],
            "tool_resources" => [
                "file_search" => [
                    "vector_store_ids" => [$vector_store_id]
                ]
            ]
        ])
    ]);

    $body_raw = wp_remote_retrieve_body($run_response);
    $body = json_decode($body_raw, true);

    if (isset($body['error'])) {
        error_log('[OPENAI run error] API: ' . print_r($body['error'], true));
        return 'Error API al ejecutar el asistente: ' . $body['error']['message'];
    }

    $run_id = $body['id'] ?? null;
    if (!$run_id) {
        error_log('[OPENAI run error] Código: ' . wp_remote_retrieve_response_code($run_response));
        error_log('[OPENAI run error] Cuerpo bruto: ' . $body_raw);
        error_log('[OPENAI run error] Decodificado: ' . print_r($body, true));
        return 'No se pudo iniciar la ejecución del asistente.';
    }

    // Esperar resultado
    do {
        sleep(1);
        $check = wp_remote_get("https://api.openai.com/v1/threads/$thread_id/runs/$run_id", [
            'headers' => [
                'Authorization' => 'Bearer ' . OPENAI_API_KEY,
                'OpenAI-Beta'   => 'assistants=v2',
            ]
        ]);
        $estado = json_decode(wp_remote_retrieve_body($check), true)['status'] ?? '';
    } while ($estado !== 'completed');

    // Obtener respuesta
    $msgs = wp_remote_get("https://api.openai.com/v1/threads/$thread_id/messages",   [
        'headers' => [
            'Authorization' => 'Bearer ' . OPENAI_API_KEY,
            'OpenAI-Beta'   => 'assistants=v2',
        ]
    ]);

    $msgs_data = json_decode(wp_remote_retrieve_body($msgs), true);
    $respuesta = $msgs_data['data'][0]['content'][0]['text']['value'] ?? 'No se pudo recuperar la respuesta.';

    // Eliminar anotaciones tipo   o similares
    $respuesta = preg_replace('/【[^】]+】/u', '', $respuesta);

    // Eliminar también posibles líneas tipo “Fuente: …” o “Sourced from …”
    $respuesta = preg_replace('/\\n?Fuente:.*$/i', '', $respuesta);
    $respuesta = preg_replace('/\\n?Sourced from.*$/i', '', $respuesta);

    return trim($respuesta);
}

// Helpers
function obtener_rutas_pdfs_wp() {
    $upload_dir = wp_upload_dir();
    $basedir = $upload_dir['basedir'];
    $pdfs = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basedir));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'pdf') {
            $pdfs[] = $file->getPathname();
        }
    }
    return $pdfs;
}

function subir_pdf_a_openai($ruta_pdf) {
    $api_key = OPENAI_API_KEY;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.openai.com/v1/files",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            "purpose" => "assistants",
            "file" => new CURLFile($ruta_pdf)
        ],
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $api_key",
            "OpenAI-Beta: assistants=v2"
        ]
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    $data = json_decode($response, true);
    return $data['id'] ?? null;
}
