<?php
/*
Plugin Name: Formulario de Registro Personalizado
Description: Plugin para registrar usuarios con un formulario personalizado.
Version: 1.0
Author: Patricia Prieto
*/
require_once plugin_dir_path(__FILE__) . 'openai-chat-pdf.php';

function consultar_openai($prompt) {

    return obtener_respuesta_desde_asistente($prompt);

}

function shortcode_openai($atts) {
    $asistente_id = obtener_o_crear_asistente_openai_v2();
}
add_shortcode('openai', 'shortcode_openai');

function openai_chat_shortcode() {

    // Usuario logado: incrementar contador de accesos
    $user_id = get_current_user_id();
    $contador = get_user_meta($user_id, 'contador_accesos', true);
    if ($contador === '') {
        $contador = 0;
    }
    $contador++;
    update_user_meta($user_id, 'contador_accesos', $contador);
    $avatar_url = get_site_url() . '/wp-content/uploads/2025/04/cervantes-avatar.png'; 
    ob_start();

    ?>

<div class="card shadow rounded-3 p-3" style="max-width: 600px; margin: auto;">
  <div id="chat-messages" class="mb-3" style="max-height: 300px; overflow-y: auto;">
    <!-- Mensajes del chat -->
  </div>
  <div class="input-group">
    <input type="text" id="user-message" class="form-control"    placeholder="Escribe tu pregunta...">
    <button id="send-button" class="btn btn-primary">Enviar</button>
  </div>
</div>

<style>
  .chat-bubble {
    padding: 10px;
    border-radius: 10px;
    max-width: 75%;
    opacity: 0;
    transform: translateY(10px);
    animation: fade-in 0.4s ease forwards;
  }

  .chat-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
  }

  @keyframes fade-in {
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }
</style>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

<script>
document.getElementById('send-button').addEventListener('click', async function () {
  const input = document.getElementById('user-message');
  const message = input.value.trim();
  if (!message) return;

  const chatBox = document.getElementById('chat-messages');

  // Añadir mensaje del usuario
  chatBox.innerHTML += `
    <div class="d-flex justify-content-end mb-2">
      <div class="chat-bubble bg-primary text-white">
        <strong>Tú:</strong> ${message}
      </div>
    </div>
  `;

  input.value = '';
  chatBox.scrollTop = chatBox.scrollHeight;

  // Añadir burbuja de "Miguel está pensando..."
  const thinkingId = 'pensando-' + Date.now();
  chatBox.innerHTML += `
    <div id="${thinkingId}" class="d-flex justify-content-start align-items-start mb-2">
      <img src="<?php echo esc_url($avatar_url); ?>" alt="Miguel avatar" class="me-2 chat-avatar">
      <div class="chat-bubble bg-light border fst-italic text-muted">
        🧠 Miguel está pensando...
      </div>
    </div>
  `;
  chatBox.scrollTop = chatBox.scrollHeight;

  // Enviar pregunta
  const formData = new FormData();
  formData.append('action', 'consultar_openai_chat');
  formData.append('prompt', message);

  const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
    method: 'POST',
    body: formData
  });

  const result = await response.text();
  const respuestaHTML = marked.parse(result); // 👈 convierte Markdown a HTML
  // Eliminar el mensaje de "pensando"
  const pensando = document.getElementById(thinkingId);
  if (pensando) pensando.remove();

  // Añadir respuesta de Miguel
  chatBox.innerHTML += `
    <div class="d-flex justify-content-start align-items-start mb-2">
      <img src="<?php echo esc_url($avatar_url); ?>" alt="Miguel avatar" class="me-2 chat-avatar">
      <div class="chat-bubble bg-light border">
        <strong>Miguel:</strong> ${respuestaHTML}
      </div>
    </div>
  `;
  chatBox.scrollTop = chatBox.scrollHeight;
});
</script>

    <?php
    return ob_get_clean();
}
add_shortcode('openai_chat', 'openai_chat_shortcode');
add_action('wp_ajax_consultar_openai_chat', 'consultar_openai_ajax');

function consultar_openai_ajax() {
    $prompt = sanitize_text_field($_POST['prompt']);
    $response = consultar_openai($prompt); 
    echo $response;
    wp_die();
}

add_action('show_user_profile', 'mostrar_contador_accesos');
add_action('edit_user_profile', 'mostrar_contador_accesos');

function mostrar_contador_accesos($user) {
    $contador = get_user_meta($user->ID, 'contador_accesos', true);
    ?>
    <h3>Estadísticas del Chat</h3>
    <table class="form-table">
        <tr>
            <th><label for="contador_accesos">Accesos al chat</label></th>
            <td>
                <input type="number" name="contador_accesos" value="<?php echo esc_attr($contador); ?>" class="regular-text" />
            </td>
        </tr>
    </table>
    <?php
}

// Agrega una página al menú de admin
add_action('admin_menu', function () {
    add_menu_page(
        'PDFs del Asistente',
        'PDFs del Asistente',
        'manage_options',
        'asistente-openai-pdfs',
        'mostrar_pdfs_asistente',
        'dashicons-media-document',
        20
    );
});

// Función que muestra los PDFs subidos
function mostrar_pdfs_asistente() {
    if (!current_user_can('manage_options')) return;

    $archivos = get_option('openai_archivos_subidos', []);
    $asistente_id = get_option('openai_asistente_lengua');

    echo '<div class="wrap">';
    echo '<h1>PDFs usados por el Asistente</h1>';

    if (empty($archivos)) {
        echo '<p>No hay archivos PDF registrados aún.</p>';
    } else {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Archivo</th><th>File ID de OpenAI</th></tr></thead><tbody>';

        foreach ($archivos as $hash => $file_id) {
            echo '<tr>';
            echo '<td>' . esc_html($hash) . '</td>';
            echo '<td><code>' . esc_html($file_id) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '<h2>Asistente</h2>';
    if ($asistente_id) {
        echo '<p><strong>ID del asistente:</strong> <code>' .   esc_html($asistente_id) . '</code></p>';
    } else {
        echo '<p>No se ha creado ningún asistente aún.</p>';
    }

    echo '</div>';
}

add_action('wp_ajax_consultar_openai_chat', 'consultar_openai_chat');
function consultar_openai_chat() {
    $prompt = sanitize_text_field($_POST['prompt'] ?? '');
    if (!$prompt) {
        echo 'No se recibió ninguna pregunta.';
        wp_die();
    }
    $respuesta = obtener_respuesta_desde_asistente($prompt);
    echo $respuesta;
    wp_die();
}

// Crear un panel de admin con el listado de usuarios y contadores
add_action('admin_menu', function () {
    add_users_page(
        'Estadísticas del Chat',
        'Estadísticas del Chat',
        'manage_options',
        'estadisticas-chat',
        'mostrar_panel_estadisticas_chat'
    );
});

function mostrar_panel_estadisticas_chat() {
    if (!current_user_can('manage_options')) return;

    $usuarios = get_users([ 'fields' => ['ID', 'display_name', 'user_email'] ]);

    echo '<div class="wrap">';
    echo '<h1>Estadísticas del Chat</h1>';
    echo '<table class="wp-list-table widefat fixed striped users">';
    echo '<thead>
            <tr>
                <th>Usuario</th>
                <th>Email</th>
                <th>Accesos al Chat</th>
            </tr>
          </thead><tbody>';

    foreach ($usuarios as $usuario) {
        $accesos = get_user_meta($usuario->ID, 'contador_accesos', true) ?: 0;
        echo '<tr>';
        echo '<td>' . esc_html($usuario->display_name) . '</td>';
        echo '<td>' . esc_html($usuario->user_email) . '</td>';
        echo '<td>' . intval($accesos) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}
