<?php
/**
 * Plugin Name: Creador de certificados manuales
 * Description: Plugin para generar certificados manualmente
 * Version: 1.0
 * Author: Oracle Perú S.A.C.
 */

if (!defined('ABSPATH')) {
    exit;
} 


if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    if (!class_exists('TCPDF')) {
        require_once(__DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php');
    }
    if (!class_exists('QRcode')) {
        require_once(__DIR__ . '/lib/phpqrcode/qrlib.php');
    }
}
require_once __DIR__ . '/vendor/autoload.php';
use Google\Client;
use Google\Service\Drive;
use Google_Service_Drive;

class Manual_Certificate_Generator {
    private $config;
    private $imageGenerator;
    private $pdfGenerator;
    private $driveUploader;
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));

        $this->config = [
            'root_folder_id' => '1pV2eir2fauUaaTJaToUXeVTjIpVfVLTB',
            'template_path' =>[
                    'slide1' => __DIR__ . '/assets/templates/Diapositiva1.png',
                    'slide2' => __DIR__ . '/assets/templates/Diapositiva2.png',
                    'syllabus_prime_auxi' => __DIR__ . '/assets/templates/syllabus/primer_auxi.png',
                    'syllabus_trab_altura' => __DIR__ . '/assets/templates/syllabus/trabajos_altura.png',
                    'syllabus_espacios_confi' => __DIR__ . '/assets/templates/syllabus/espacios_confinados.png',
                    'syllabus_riesgo_electrico' => __DIR__ . '/assets/templates/syllabus/riesgo_electrico.png',
                    'logo' => __DIR__ . '/assets/logo.png',

            ],
            'fonts' => [
                'nunito' => __DIR__ . '/assets/fonts/Nunito-Italic-VariableFont_wght.ttf',
                'arimo' => __DIR__ . '/assets/fonts/Arimo-Italic-VariableFont_wght.ttf',
                'dm_serif' => __DIR__ . '/assets/fonts/DMSerifText-Regular.ttf'
            ],
            'signature' =>[
                'certificate' => __DIR__ . '/assets/digital-signature/public.crt',
                'key' => __DIR__ . '/assets/digital-signature/private.key',
                'password' => 'gutemberg192837465'
            ]
            ];
     
    }

    public function add_admin_menu() {
        add_menu_page(
            'Generador de Certificados',  // Título de la página
            'Certificados',               // Título del menú
            'manage_options',             // Capacidad requerida
            'manual-certificates',        // Slug del menú
            array($this, 'render_admin_page'), // Callback
            'dashicons-awards',           // Icono
            30                           // Posición
        );
    }

    public function render_admin_page() {
        if(isset($_POST['submit_data'])){
            
            // auqi llamamos a todos los metodos para generar el certificado
            $result = $this->handle_data_form();
            echo '<pre>';
            print_r($result);
            echo '</pre>';
            // gett time 
            echo current_time('mysql'); // Formato: YYYY-MM-DD HH:MM:SS
            echo current_time('timestamp'); // Unix timestamp

            // O usando DateTime
            $wp_timezone = get_option('timezone_string');
            $date = new DateTime('now', new DateTimeZone($wp_timezone ?: 'UTC'));
            echo $date->format('Y-m-d H:i:s');
            $manualCerficateQrGenerator = new ManualCertificateQrGenerator($this->config);
            $qr_code = $manualCerficateQrGenerator->generate_qr($result['codigo_unico']);
            $manualCertificateImgGenerator = new ManualCertificateImgGenerator($this->config, $manualCerficateQrGenerator, $result);
            $certificate_images = $manualCertificateImgGenerator->generateCertificateImages($result, $result['codigo_unico']);
            $manualCertificatePDFGenerator = new ManualCertificatePDFGenerator($this->config);
            // $fileName = 'certificado_subido.pdf';
            // $folderId = '1pV2eir2fauUaaTJaToUXeVTjIpVfVLTB'; 
            $pdf_path = $manualCertificatePDFGenerator->generateSignedPDF($certificate_images);
            //$uploadResult = $this->pruebauploadpdftodrive($pdf_path, $fileName, $folderId);

            
            // // Asegúrate de que la ruta sea absoluta
            // $fileName = 'certificado_' . $result['dni'] . '.pdf';
            // $folderId = $this->config['root_folder_id'];

            // $uploadResult = $this->pruebauploadpdftodrive($pdf_path, $fileName, $folderId);
            
            // if ($uploadResult) {
            //     echo "<div class='notice notice-success'>";
            //     echo "<p> Archivo subido con éxito.</p>";
            //     echo "<p>ID: " . esc_html($uploadResult['id']) . "</p>";
            //     echo "<p>URL: <a href='" . esc_url($uploadResult['url']) . "' target='_blank'>Ver archivo</a></p>";
            //     echo "</div>";
            // }
 
            
            $manualCertificateDriveUploader = new ManualCertificateDriveUploader($this->config);
            $url_drive = $manualCertificateDriveUploader->uploadToDrive
            ($pdf_path, $result);
            echo '<pre>';
            echo 'URL DRIVE';
            print_r($url_drive);
            echo '</pre>';
            $result['enlace_drive'] = $url_drive;
            $this->saveDataToDatabase($result);
            // builld validation in front
            if($url_drive){
                add_settings_error(
                    'certificate_messages',
                    'certificate_success',
                    'Certificado creado correctamente',
                    'updated'
                );
            }else{
                add_settings_error(
                    'certificate_messages',
                    'certificate_error',
                    'Error al crear el certificado',
                    'error'
                );
            }
        }
        
        settings_errors('certificate_messages');

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="">
                <?php wp_nonce_field('create_certificate_nonce', 'certificate_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="name">Apellido y Nombre</label></th>
                        <td><input type="text" name="name" id="name"></td>
                    </tr>
                    <tr>
                        <th><label for="dni">DNI</label></th>
                        <td><input type="text" name="dni" id="dni"></td>
                    </tr> 
                    <tr>
                        <th><label for="course">Curso o Capacitacion</label></th>
                        <td>
                            <select name="courses" id="courses">
                                <option value="syllabus_prime_auxi">Primeros Axuilios</option>
                                <option value="syllabus_trab_altura">Trabajo en Altura</option>
                                <option value="syllabus_espacios_confi">Espacios Confinados</option>
                                <option value="syllabus_riesgo_electrico">Riesgo Electrico</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mode">Modalidad</label></th>
                        <td>
                            <select name="mode" id="mode">
                                <option value="presencial">Presencial</option>
                                <option value="virtual">Virtual</option>
                            </select>
                    </tr>

                    <tr>
                        <th><label for="hours">Horas academicas</label></th>
                        <td><input type="number" name="hours" id="hours"></td>
                    </tr>
                    <tr>
                        <th><label for="program_formation">Tipo de formacion</label></th>
                        <td>
                            <select name="type_formation" id="type_formation">
                                <option value="c-t-practico">Capacitación Teorico Práctico</option>
                                <option value="c-t-taller-practico">Capacitación teórico con Taller Practico</option>
                                <option value="cu-ta-pra">Curso con Taller Practico</option>
                                <option value="c-t-con-taller-prac">Curso Teórico con Taller Practico</option>
                            </select>
                        </td>

                    </tr>
                    <tr>
                        <th><label for="address">Lugar de Capacitación</label></th>
                        <td><input type="text" name="address" id="address"></td>
                    </tr>
                    <tr>
                        <th><label for="note">Nota</label></th>
                        <td><input type="number" name="note" id="note"></td>
                    </tr>
                    <tr>
                        <th><label for="date">Fecha de emisión</label></th>
                        <td><input type="date" name="date" id="date"></td>
                    </tr>
                    <tr>
                        <th><label for="type_couser">Tipo de Capacitacion</label></th>
                        <td>
                            <select name="type_couser" id="type_couser">
                                <option value="capacitacion">Capacitacion</option>
                                <option value="curso">Curso</option>
                            </select>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="submit_data" value="Generar Certificado" class="button button-primary">
                </p>


            </form>
        </div>
        <?php

      
    }
        private function pruebauploadpdftodrive($filePath, $fileName, $folderId) {
        try {
            // Verificar si el archivo existe y es legible
            if (!file_exists($filePath)) {
                throw new Exception('El archivo no existe en: ' . $filePath);
            }
    
            if (!is_readable($filePath)) {
                throw new Exception('El archivo no es legible: ' . $filePath);
            }
    
            $client = new Client();
            $client->setAuthConfig(__DIR__ . '/credentials.json');
            $client->addScope(Drive::DRIVE_FILE);
            
            $service = new Drive($client);
            
            // Crear metadata del archivo
            $file = new Drive\DriveFile([
                'name' => $fileName,
                'parents' => [$folderId],
                'mimeType' => 'application/pdf'
            ]);
    
            // Leer el contenido del archivo
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new Exception('No se pudo leer el contenido del archivo');
            }
    
            // Subir archivo
            $result = $service->files->create(
                $file,
                [
                    'data' => $content,
                    'mimeType' => 'application/pdf',
                    'uploadType' => 'multipart',
                    'fields' => 'id, webViewLink'
                ]
            );
    
            // Hacer el archivo público
            $permission = new Drive\Permission([
                'type' => 'anyone',
                'role' => 'reader'
            ]);
            
            $service->permissions->create($result->id, $permission);
    
            return [
                'id' => $result->id,
                'url' => $result->webViewLink
            ];
    
        } catch (Exception $e) {
            error_log('Error al subir archivo a Drive: ' . $e->getMessage());
            throw $e;
        }
    }

    private function generate_custom_code(){
        return 'CERT-' . date('Y') . '-' . strtoupper(wp_generate_uuid4());
    }
    private function handle_data_form(){
        if(!isset($_POST['certificate_nonce']) || !wp_verify_nonce($_POST['certificate_nonce'], 'create_certificate_nonce')){
            wp_die(
                sprintf(
                    'La verificación de seguridad falló.<br>
                    Nonce esperado: %s<br>
                    Nonce recibido: %s<br>
                    <a href="%s">Volver al formulario</a>',
                    'create_certificate_nonce',
                    isset($_POST['certificate_nonce']) ? esc_html($_POST['certificate_nonce']) : 'no definido',
                    esc_url(admin_url('admin.php?page=manual-certificates'))
                ),
                'Error de Seguridad',
                array('response' => 403)
            );

        }
        $code_unique = $this->generate_custom_code();
        $url_dowload_drve = 0;
        $certificate_data = array( 
            'dni' => sanitize_text_field($_POST['dni']),
            'codigo_unico' => $code_unique,
            'nombre' => sanitize_text_field($_POST['name']), 
            'curso' => sanitize_text_field($_POST['courses']),
            'mode' => sanitize_text_field($_POST['mode']),
            'hours' => sanitize_text_field($_POST['hours']),
            'type_formation' => sanitize_text_field($_POST['type_formation']),
            'address' => sanitize_text_field($_POST['address']),
            'nota' => sanitize_text_field($_POST['note']),
            'fecha_emision' => sanitize_text_field($_POST['date']),
            'type_couser' => sanitize_text_field($_POST['type_couser']),
            'enlace_drive' => $url_dowload_drve,

        );   

        return $certificate_data;

    }

    private function saveDataToDatabase($certificate_data){
        if($this->create_not_exist()){
            $this->create_database_table();
        }

        global $wpdb;
        $tabe_name = $wpdb->prefix . 'certificados_manuales';
        $insert = $wpdb->insert(
            $tabe_name,
            $certificate_data,
            array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')
        );
        if($insert){
            add_settings_error(
                'certicate_messages',
                'certificate_success',
                'Certificado creado correctamente',
                'update'
            );
        }else{
            add_settings_error(
                'certificate_messages',
                'certificate_error',
                'Error al crear el certificado',
                'error'

            );
        }
        // show all data if validation in front
        settings_errors('certificate_messages');

    }


    public function create_not_exist(){
        global $wpdb;
        $table_name = $wpdb->prefix . 'certificados_manuales';
        $consult = "SHOW TABLES LIKE '$table_name'";
        $result = $wpdb->get_results($consult);
        return count($result) === 0;
    }

    private function create_database_table(){
        global $wpdb;
        $character_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'certificados_manuales';
        $sql = "
        CREATE TABLE IF NOT EXISTS $table_name (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dni VARCHAR(20) NULL,
            codigo_unico VARCHAR(100) NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            curso VARCHAR(100) NOT NULL,
            mode ENUM('presencial', 'virtual') DEFAULT 'presencial',
            hours INT,
            type_formation ENUM('c-t-practico', 'c-t-taller-practico', 'cu-ta-pra', 'c-t-con-taller-prac') DEFAULT 'c-t-practico',
            address VARCHAR(100),
            nota FLOAT,
            fecha_emision DATE,
            type_couser ENUM('capacitacion', 'curso') DEFAULT 'capacitacion',
            enlace_drive VARCHAR(255) NULL,
            error_message TEXT NULL,
            last_attempt DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY codigo_unico (codigo_unico)
        ) $character_collate;";
        require_once(ABSPATH. 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

    }
}


class ManualCertificateQrGenerator{
    private $config;
    public function __construct( $config){
        $this->config = $config;
    }

    public function generate_qr($code){
        if(!class_exists('QRcode')){
            echo '<pre>';
            echo 'No se encuentra la clase QRcode';
            echo '</pre>';
        }
        $qr_dir = plugin_dir_path(__FILE__) . 'assets/qr-codes/';
        wp_mkdir_p($qr_dir);
        $qr_filename = 'qr-' . $code . '.png';
        $qr_file = $qr_dir . $qr_filename;
        $current_url = add_query_arg('code', urldecode($code), get_permalink());
        QRcode::png($current_url, $qr_file, QR_ECLEVEL_H, 15, 2, false);
        $QR = imagecreatefrompng($qr_file);
        $logo = imagecreatefrompng($this->config['template_path']['logo']);
        $this->overlayLogoOnQr($QR, $logo);
        imagepng($QR, $qr_file);
        imagedestroy($QR);
        return $QR;

    }

    private function overlayLogoOnQr(&$QR, $logo){
        $QR_width = imagesx($QR);
        $QR_height = imagesy($QR);
        $logo_width = imagesx($logo);
        $logo_height = imagesy($logo);
        $logo_qr_width = $QR_width / 5;
        $scale = $logo_width / $logo_qr_width;
        $logo_qr_height = $logo_height / $scale;
        $from_width = ($QR_width - $logo_qr_width) / 2;
        $from_height = ($QR_height - $logo_qr_height) / 2; 
        imagecopyresampled($QR, $logo, $from_width, $from_height, 0, 0, $logo_qr_width, $logo_qr_height, $logo_width, $logo_height);
    }
}

class ManualCertificateImgGenerator{
    private $config;
    private $qrGenerator;
    private $dataFilteredoForSyllabus;

    public function __construct( $config, ManualCertificateQrGenerator $qrGenerator, $dataFilteredoForSyllabus){
        $this->config = $config;
        $this->qrGenerator = $qrGenerator;
        $this->dataFilteredoForSyllabus = $dataFilteredoForSyllabus;
    }

    public function generateCertificateImages($data, $code){
        try{
            $curso = $data['curso'];
            $template = $this->loadTemplate($curso);
            $color = $this->defineColors($template);
            $this->renderFirstTemplateText($template['base1'], $data, $color, $code);
            $this->renderSecondTemplateText($template['base2'], $data, $color);
            $qr_code = $this->qrGenerator->generate_qr($code);
            $this->addQRAndSyllabus($template, $qr_code);
            $output_paths = $this->saveImages($template);

            $this->cleanupResources($template, $qr_code);
            return $output_paths;
        }catch(Exception $e){
            error_log('Error editando las imagenes: ' . $e->getMessage());
            throw $e;

        }
    }
    private function filter_syllavus($course){
        $syllabus = [
            'prim-axui' => 'syllabus_prime_auxi',
            'trab-altura' => 'syllabus_trab_altura',
            'esp-confi' => 'syllabus_espacios_confi',
            'riesgo-electrico' => 'syllabus_riesgo_electrico'
        ];
        return $syllabus[$course];
    }
    private function loadTemplate($syllabus){
        $template_paths = $this->config['template_path'];
            
        // Verificar que los archivos existen
        if (!file_exists($template_paths['slide1'])) {
            error_log('No se encuentra el archivo: ' . $template_paths['slide1']);
            throw new Exception('No se encuentra la plantilla base 1');
        }
        
        if (!file_exists($template_paths['slide2'])) {
            error_log('No se encuentra el archivo: ' . $template_paths['slide2']);
            throw new Exception('No se encuentra la plantilla base 2');
        }
        
        if (!file_exists($template_paths[$syllabus])) {
            error_log('No se encuentra el archivo: ' . $template_paths[$syllabus]);
            echo '<pre>';
            echo 'No se encuentra el archivo: ' . $template_paths[$syllabus] . '<br>';
            //print_r($template_paths);
            print_r($syllabus);
            echo '</pre>';
            throw new Exception('No se encuentra la plantilla del syllabus');

        }
        
        $base1 = imagecreatefrompng($template_paths['slide1']);
        $base2 = imagecreatefrompng($template_paths['slide2']);
        $syllabus_img = imagecreatefrompng($template_paths[$syllabus]);
        
        if (!$base1 || !$base2 || !$syllabus_img) {
            throw new Exception('Error al cargar una o más imágenes');
        }
        return [
            'base1' => imagecreatefrompng($template_paths['slide1']),
            'base2' => imagecreatefrompng($template_paths['slide2']),
            'syllabus' => imagecreatefrompng($template_paths[$syllabus]),
        ];
    } 

    private function defineColors($images){
        return [
            'name' => imagecolorallocate($images['base1'], 0, 32, 96),
            'dni' => imagecolorallocate($images['base1'], 53, 55, 68),
            'course' => imagecolorallocate($images['base1'], 7, 55, 99),
            'details' => imagecolorallocate($images['base1'], 53, 55, 68),
            'code' => imagecolorallocate($images['base1'], 66, 66, 66),
            'date' => imagecolorallocate($images['base1'], 53, 55, 68),
            'course2' => imagecolorallocate($images['base2'], 35, 58, 68)
        ];
    }

    private function renderFirstTemplateText($image, $data, $colors, $code) {
        $fonts = $this->config['fonts'];
        $text_configs = [
            ['text' => $data->nombre, 'x' => 350, 'y' => 325, 'size' => 30, 'font' => $fonts['nunito'], 'color' => $colors['name']],
            ['text' => $data->dni, 'x' => 675, 'y' => 382, 'size' => 14, 'font' => $fonts['arimo'], 'color' => $colors['dni']],
            ['text' => $data->curso, 'x' => 430, 'y' => 438, 'size' => 25, 'font' => $fonts['dm_serif'], 'color' => $colors['course']],
            ['text' => $data->mode, 'x' => 300, 'y' => 400, 'size' => 14, 'font' => $fonts['arimo'], 'color' => $colors['details']],
            ['text' => $data->hours, 'x' => 300, 'y' => 470, 'size' => 14, 'font' => $fonts['arimo'], 'color' => $colors['details']],
            ['text' => $data->type, 'x' => 300, 'y' => 440, 'size' => 14, 'font' => $fonts['arimo'], 'color' => $colors['details']],
            ['text' => $data->address, 'x' => 300, 'y' => 410, 'size' => 14, 'font' => $fonts['arimo'], 'color' => $colors['details']],
            ['text' => date('d/m/Y', strtotime($data->ultima_fecha)), 'x' => 750, 'y' => 565, 'size' => 14, 'font' => $fonts['arimo'], 'color' => $colors['date']],
            ['text' => $code, 'x' => 275, 'y' => 565, 'size' => 14, 'font' => $fonts['dm_serif'], 'color' => $colors['code']]
        ];

        foreach ($text_configs as $config) {
            imagettftext(
                $image, 
                $config['size'], 
                0, 
                $config['x'], 
                $config['y'], 
                $config['color'], 
                $config['font'], 
                $config['text']
            );
        }
    }

    private function renderSecondTemplateText($image, $data, $colors) {
        $fonts = $this->config['fonts'];
        $text_configs = [
            ['text' => $data->curso, 'x' => 360, 'y' => 100, 'size' => 39, 'font' => $fonts['dm_serif'], 'color' => $colors['course2']],
            ['text' => $data->type_couser, 'x' => 360, 'y' => 150, 'size' => 24, 'font' => $fonts['dm_serif'], 'color' => $colors['course2']],
            ['text' => 'Aprobado', 'x' => 220, 'y' => 200, 'size' => 36, 'font' => $fonts['dm_serif'], 'color' => $colors['course2']],
            ['text' => number_format($data->nota, 1), 'x' => 720, 'y' => 200, 'size' => 36, 'font' => $fonts['nunito'], 'color' => $colors['course2']]
        ];

        foreach ($text_configs as $config) {
            imagettftext(
                $image, 
                $config['size'], 
                0, 
                $config['x'], 
                $config['y'], 
                $config['color'], 
                $config['font'], 
                $config['text']
            );
        }
    }

    private function addQRAndSyllabus($templates, $qr_code) {
        imagecopy($templates['base1'], $qr_code, 20, 20, 0, 0, 100, 100);
        imagecopy($templates['base2'], $templates['syllabus'], 170, 335, 0, 0, 800, 300);
    }
    private function saveImages($templates) {
        $output_paths = [
            __DIR__ . '/certificates/png/certificado1_edited.png',
            __DIR__ . '/certificates/png/certificado2_edited.png'
        ];
        
        imagepng($templates['base1'], $output_paths[0]);
        imagepng($templates['base2'], $output_paths[1]);

        return $output_paths;
    }


    private function cleanupResources($templates, $qr_code) {
        foreach ($templates as $image) {
            imagedestroy($image);
        }
        imagedestroy($qr_code);
    }
}

class ManualCertificatePDFGenerator {
    private $config;

    public function __construct( $config) {
        $this->config = $config;
    }

    public function generateSignedPDF($certificate_images) {
        try {
            $pdf = new TCPDF('L', 'mm', 'A4');
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(0, 0, 0);

            // Add first page
            $pdf->AddPage();
            $pdf->Image($certificate_images[0], 0, 0, 297, 210);

            // Add second page
            $pdf->AddPage();
            $pdf->Image($certificate_images[1], 0, 0, 297, 210);

            // Set document signing properties
            $pdf->setSignature(
                $this->config['signature']['certificate'],
                $this->config['signature']['key'],
                $this->config['signature']['password']
            );

            $output_path = __DIR__ . '/certificates/pdf/certificado.pdf';
            $pdf->Output($output_path, 'F');

            return $output_path;

        } catch (Exception $e) {
            error_log("Error generating PDF: " . $e->getMessage());
            throw $e;
        }
    }
}



class ManualCertificateDriveUploader {
    private $config;
    private $google_client;
    private $service;

    public function __construct($config) {
        $this->config = $config;
        $this->initializeGoogleClient();
    }

    private function initializeGoogleClient() {
        try {
            $this->google_client = new Google_Client();
            $this->google_client->setApplicationName('Certificate Generator');
            
            // Check if credentials file exists
            $credentials_path = __DIR__ . '/credentials.json';
            if (!file_exists($credentials_path)) {
                throw new Exception('Credentials file not found at: ' . $credentials_path);
            }

            $this->google_client->setAuthConfig($credentials_path);
            $this->google_client->setScopes(['https://www.googleapis.com/auth/drive.file']);
            
            $this->service = new Google_Service_Drive($this->google_client);
        } catch (Exception $e) {
            error_log('Google Client initialization error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function uploadToDrive($file_path, $certificateData) {
        try {
            if (!file_exists($file_path)) {
                throw new Exception('File not found: ' . $file_path);
            }
            error_log('Uploading file: ' . $file_path);
            error_log('Root folder ID: ' . $this->config['root_folder_id']);
            
            // Create folder structure
            $folder_path = date('Y') . '/' . $certificateData['curso'] . '/' . $certificateData['nombre'];
            error_log('Creating folder structure: ' . $folder_path);
            
            $folder_id = $this->create_folder_structure($folder_path);
            error_log('Created/Found folder ID: ' . $folder_id);

            // Create folder structure
            $folder_path = date('Y') . '/' . $certificateData['curso'] . '/' . $certificateData['nombre'];
            $folder_id = $this->create_folder_structure($folder_path);

            // Prepare file metadata
            $file_metadata = new Google_Service_Drive_DriveFile([
                'name' => "certificado_{$certificateData['dni']}.pdf",
                'parents' => [$folder_id],
                'mimeType' => 'application/pdf'
            ]);

            // Upload file
            $file = $this->service->files->create(
                $file_metadata,
                [
                    'data' => file_get_contents($file_path),
                    'mimeType' => 'application/pdf',
                    'uploadType' => 'multipart',
                    'fields' => 'id, webViewLink'
                ]
            );

            // Set public permissions
            $permission = new Google_Service_Drive_Permission([
                'type' => 'anyone',
                'role' => 'reader'
            ]);
            
            $this->service->permissions->create($file->getId(), $permission);

            return $file->getWebViewLink();

        } catch (Exception $e) {
            error_log('Upload to Drive error: ' . $e->getMessage());
            throw new Exception('Error uploading to Drive: ' . $e->getMessage());
        }
    }


private function create_folder_structure($folder_path) {
    try {
        // Verify root folder ID exists
        if (empty($this->config['root_folder_id'])) {
            throw new Exception('Root folder ID is not configured');
        }

        $current_parent = $this->config['root_folder_id'];
        $folders = explode('/', $folder_path);

        foreach ($folders as $folder_name) {
            $folder_name = trim($folder_name);
            if (empty($folder_name)) continue;

            // Create search query
            $query = "mimeType='application/vnd.google-apps.folder' and name='" . 
                    addslashes($folder_name) . "' and '" . 
                    $current_parent . "' in parents and trashed=false";

            // Search for existing folder
            $results = $this->service->files->listFiles([
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name)',
                'pageSize' => 1
            ]);

            // Create or get folder ID
            if ($results->getFiles()) {
                $current_parent = $results->getFiles()[0]->getId();
            } else {
                // Create new folder
                $folder_metadata = new Google_Service_Drive_DriveFile([
                    'name' => $folder_name,
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents' => [$current_parent]
                ]);

                try {
                    $folder = $this->service->files->create($folder_metadata, ['fields' => 'id']);
                    if (!$folder || !$folder->getId()) {
                        throw new Exception("Failed to create folder: $folder_name");
                    }
                    $current_parent = $folder->getId();
                } catch (Exception $e) {
                    throw new Exception("Error creating folder '$folder_name': " . $e->getMessage());
                }
            }
        }

        return $current_parent;

    } catch (Exception $e) {
        error_log('Create folder structure error: ' . $e->getMessage());
        throw new Exception('Failed to create folder structure: ' . $e->getMessage());
    }
}


}
// class ManualCertificateDriveUploader {
//     private $config;
//     private $google_client;
//     private $service;

//     public function __construct( $config) {
//         $this->config = $config;
       
//     }


//     public function uploadToDrive($file_path, $certificateData) {
//         // TODO: Implement Google Drive upload logic
//         try {
//             // Initialize Google Client if not already done
//             if (!$this->google_client) {
//                 $this->google_client = new Google_Client();
//                 $this->google_client->setAuthConfig(__DIR__ . "/credentials.json");
//                 $this->google_client->addScope(Google_Service_Drive::DRIVE);
//             }

//             $service = new Google_Service_Drive($this->google_client);

//             // Create folder structure: year/course/student_name
//             $folder_path = date('Y') . '/' . $certificateData->curso . '/' . $certificateData->nombre;
//             $folder_id = $this->create_folder_structure($service, $folder_path);

//             // Prepare file metadata
//             $file_metadata = new Google_Service_Drive_DriveFile([
//                 'name' => "certificado_{$certificateData->dni}.pdf",
//                 'parents' => [$folder_id]
//             ]);

//             // Upload file
//             $content = file_get_contents($file_path);
//             $file = $service->files->create($file_metadata, [
//                 'data' => $content,
//                 'mimeType' => 'application/pdf',
//                 'uploadType' => 'multipart',
//                 'fields' => 'id'
//             ]);

//             // Set file permissions (public read access)
//             $permission = new Google_Service_Drive_Permission([
//                 'type' => 'anyone',
//                 'role' => 'reader',
//             ]);
//             $service->permissions->create($file->id, $permission);

//             // Generate download URL
//             $download_url = "https://drive.google.com/uc?export=download&id=" . $file->id;

            
//             error_log("File uploaded to Google Drive: " . $download_url);
//             return $download_url;

//         } catch (Exception $e) {
//             error_log("Error uploading to Google Drive: " . $e->getMessage());                   
//         }
// }


//     private function create_folder_structure($service, $folder_path) {
    

//         $current_parent = $this->config['root_folder_id'];
//         $folders = explode('/', $folder_path);

//         foreach ($folders as $folder_name) {
//             // Search for existing folder
//             $query = "mimeType='application/vnd.google-apps.folder' and name='$folder_name' and '$current_parent' in parents and trashed=false";
//             $results = $service->files->listFiles([
//                 'q' => $query,
//                 'spaces' => 'drive',
//                 'fields' => 'files(id, name)'
//             ]);

//             // Create folder if it doesn't exist
//             if (count($results->getFiles()) == 0) {
//                 $folder_metadata = new Google_Service_Drive_DriveFile([
//                     'name' => $folder_name,
//                     'mimeType' => 'application/vnd.google-apps.folder',
//                     'parents' => [$current_parent]
//                 ]);
//                 $folder = $service->files->create($folder_metadata, ['fields' => 'id']);
//                 $current_parent = $folder->id;
//             } else {
//                 $current_parent = $results->getFiles()[0]->getId();
//             }
//         }

//         return $current_parent;
//     }

// }


// Inicializar el plugin
function initialize_manual_certificate_generator() {
    
    
    
    new Manual_Certificate_Generator();
}
add_action('plugins_loaded', 'initialize_manual_certificate_generator');