<?php
/**
 * 
 * Author: Iftekhar Ahmed Eather
 * Title: HEIC to JPG Image Converter with Batch Processing
 * 
 **/

/**
 * 
 * Increase memory and execution time for batch processing
 * 
 * 
 **/

ini_set('memory_limit', '512M');
set_time_limit(300);

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['images'])) {
    $files = $_FILES['images'];
    $fileCount = count($files['name']);
    
    if ($fileCount > 0 && $files['error'][0] === 0) {
        $zip = new ZipArchive();
        $zipName = "converted_images_" . time() . ".zip";
        $zipPath = sys_get_temp_dir() . '/' . $zipName;

        if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
            exit("Cannot open <$zipPath>\n");
        }

        for ($i = 0; $i < $fileCount; $i++) {
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            
            if ($ext === 'heic') {
                try {
                    $imagick = new Imagick();
                    $imagick->readImage($files['tmp_name'][$i]);
                    
                    // High Quality Settings
                    $imagick->setImageFormat('jpeg');
                    
                    $imagick->setSamplingFactors(['1x1', '1x1', '1x1']); //this setting increases the output file size

                    $imagick->setImageCompressionQuality(95); // for quality/size
                    
                    $newFileName = pathinfo($files['name'][$i], PATHINFO_FILENAME) . ".jpg";
                    
                    // Add converted image to ZIP
                    $zip->addFromString($newFileName, $imagick->getImageBlob());
                    
                    $imagick->clear();
                    $imagick->destroy();
                } catch (Exception $e) {
                    $message .= "Error converting " . $files['name'][$i] . ": " . $e->getMessage() . "<br>";
                }
            }
        }

        $zip->close();

        // Push the ZIP to the browser
        if (file_exists($zipPath)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="images_converted.zip"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);
            unlink($zipPath); // Delete temp file after download
            exit;
        }
    } else {
        $message = "Please select one or more HEIC files.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Bulk HEIC to JPG Converter</title>
    <style>
        body { font-family: 'Segoe UI', Verdana, sans-serif; background: #f4f7f6; display: flex; justify-content: center; padding: 50px; }
        .container { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 400px; text-align: center; }
        .error { color: #d9534f; background: #f2dede; padding: 10px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
        input[type="file"] { margin: 20px 0; }
        button { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-size: 16px; }
        button:hover { background: #218838; }
    </style>
</head>
<body>
    <div class="container">
        <h2>HEIC Batch Converter</h2>
        <p>Convert multiple HEIC to JPG & Download ZIP</p>
        
        <?php if($message): ?>
            <div class="error"><?php echo $message; ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="images[]" accept=".heic" multiple required>
            <br>
            <button type="submit">Convert & Download ZIP</button>
        </form>
    </div>
</body>
</html>