<?php
/*
Plugin Name: ICC Upload & Shorten
Plugin URI: https://github.com/ivancarlosti/YOURLS-Upload-and-Shorten
Description: Upload a file locally or to AWS S3 and create a short-YOURL for it in one step.
Version: 1.0
Author: Ivan Carlos
Author URI: https://github.com/ivancarlosti
*/

// No direct call
if (!defined('YOURLS_ABSPATH'))
	die();

// Register our plugin admin page
yourls_add_action('plugins_loaded', 'icc_upload_and_shorten_add_page');
yourls_add_action('admin_init', 'icc_upload_and_shorten_handle_ajax');
yourls_add_action('load-plugins_page_icc_upload_and_shorten', 'icc_upload_and_shorten_cleanup_temp'); // Run cleanup on plugin page load

function icc_upload_and_shorten_add_page()
{
	// create entry in the admin's plugin menu
	yourls_register_plugin_page('icc_upload_and_shorten', 'Upload & Shorten', 'icc_upload_and_shorten_do_page');
}

// Handle AJAX requests for chunked uploads
function icc_upload_and_shorten_handle_ajax()
{
	if (isset($_POST['action']) && ($_POST['action'] == 'icc_upload_chunk' || $_POST['action'] == 'icc_upload_finish')) {
		// Ensure user is authenticated
		if (!yourls_is_valid_user()) {
			echo json_encode(['status' => 'error', 'message' => 'Authentication failed']);
			die();
		}

		if ($_POST['action'] == 'icc_upload_chunk') {
			icc_upload_and_shorten_handle_chunk();
		}
		if ($_POST['action'] == 'icc_upload_finish') {
			icc_upload_and_shorten_handle_finish();
		}
	}
}

function icc_upload_and_shorten_handle_chunk()
{
	$nonce = $_POST['nonce'] ?? '';
	if (!yourls_verify_nonce('icc_upload_chunk', $nonce)) {
		echo json_encode(['status' => 'error', 'message' => 'Security check failed']);
		die();
	}

	if (!isset($_FILES['file_chunk']) || $_FILES['file_chunk']['error'] != UPLOAD_ERR_OK) {
		echo json_encode(['status' => 'error', 'message' => 'Upload error']);
		die();
	}

	$upload_id = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['upload_id']);
	$temp_dir = yourls_get_option('icc_upload_share_dir');
	if (!$temp_dir)
		$temp_dir = sys_get_temp_dir();

	// Create a temp directory for this upload
	$target_dir = rtrim($temp_dir, '/') . '/icc_temp_' . $upload_id;
	if (!is_dir($target_dir))
		mkdir($target_dir, 0755, true);

	$chunk_index = intval($_POST['chunk_index']);
	$target_file = $target_dir . '/part_' . $chunk_index;

	if (move_uploaded_file($_FILES['file_chunk']['tmp_name'], $target_file)) {
		echo json_encode(['status' => 'success']);
	} else {
		echo json_encode(['status' => 'error', 'message' => 'Failed to move chunk']);
	}
	die();
}

function icc_upload_and_shorten_handle_finish()
{
	$nonce = $_POST['nonce'] ?? '';
	if (!yourls_verify_nonce('icc_upload_chunk', $nonce)) {
		echo json_encode(['status' => 'error', 'message' => 'Security check failed']);
		die();
	}

	$upload_id = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['upload_id']);
	$file_name = $_POST['file_name'];
	$temp_dir = yourls_get_option('icc_upload_share_dir');
	if (!$temp_dir)
		$temp_dir = sys_get_temp_dir();

	$target_dir = rtrim($temp_dir, '/') . '/icc_temp_' . $upload_id;
	$final_file_path = $target_dir . '/' . $file_name;

	// Assemble chunks
	if ($fp = fopen($final_file_path, 'wb')) {
		$chunks = glob($target_dir . '/part_*');
		natsort($chunks);
		foreach ($chunks as $chunk) {
			$chunk_content = file_get_contents($chunk);
			fwrite($fp, $chunk_content);
			unlink($chunk);
		}
		fclose($fp);
		rmdir($target_dir); // Remove temp dir

		// now process the file
		// Pass essential POST data for filename conversion if needed

		$result = icc_upload_and_shorten_process_upload($final_file_path, $file_name);

		// Since the result is HTML string, we might want to return it or parse it
		// But for this AJAX response we return it in message
		echo json_encode(['status' => 'success', 'message' => $result]);
	} else {
		echo json_encode(['status' => 'error', 'message' => 'Failed to assemble file']);
	}
	die();
}

// Display admin page
function icc_upload_and_shorten_do_page()
{
	// Check if a form was submitted
	if (isset($_POST['action']) && $_POST['action'] == 'icc_upload_and_shorten_save') {
		icc_upload_and_shorten_update_settings();
	}

	// Handle Deletion
	if (isset($_POST['action']) && $_POST['action'] == 'delete_local_file' && isset($_POST['file_name'])) {
		$nonce = $_POST['nonce'] ?? '';
		if (yourls_verify_nonce('icc_delete_local_file', $nonce)) {
			$share_dir = yourls_get_option('icc_upload_share_dir');
			$file_name = $_POST['file_name'];
			// Validating filename to prevent directory traversal
			if (basename($file_name) == $file_name) {
				$file_path = rtrim($share_dir, '/') . '/' . $file_name;
				if (file_exists($file_path)) {
					if (unlink($file_path)) {
						echo "<div class='updated'>File deleted successfully: " . htmlspecialchars($file_name) . "</div>";
					} else {
						echo "<div class='error'>Failed to delete file. Check permissions.</div>";
					}
				} else {
					echo "<div class='error'>File not found.</div>";
				}
			} else {
				echo "<div class='error'>Invalid filename.</div>";
			}
		} else {
			echo "<div class='error'>Security check failed.</div>";
		}
	}

	if (isset($_POST['action']) && $_POST['action'] == 'delete_file' && isset($_POST['file_key'])) {
		$nonce = $_POST['nonce'] ?? '';
		if (yourls_verify_nonce('icc_delete_file', $nonce)) {
			try {
				$s3_key = yourls_get_option('icc_upload_s3_key');
				$s3_secret = yourls_get_option('icc_upload_s3_secret');
				$s3_region = yourls_get_option('icc_upload_s3_region');
				$s3_bucket = yourls_get_option('icc_upload_s3_bucket');

				$s3 = icc_get_aws_client($s3_key, $s3_secret, $s3_region);
				if ($s3) {
					$s3->deleteObject([
						'Bucket' => $s3_bucket,
						'Key' => $_POST['file_key']
					]);
					echo "<div class='updated'>File deleted successfully: " . htmlspecialchars($_POST['file_key']) . "</div>";
				} else {
					echo "<div class='error'>Failed to initialize S3 client for deletion.</div>";
				}
			} catch (Aws\S3\Exception\S3Exception $e) {
				echo "<div class='error'>Failed to delete file: " . $e->getMessage() . "</div>";
			}
		} else {
			echo "<div class='error'>Security check failed (Invalid Nonce).</div>";
		}
	}
	
	// Manual Cleanup Handler
	if (isset($_POST['action']) && $_POST['action'] == 'icc_manual_cleanup') {
		$nonce = $_POST['nonce'] ?? '';
		if (yourls_verify_nonce('icc_manual_cleanup', $nonce)) {
			echo '<div style="background:#fff; border:1px solid #ccc; padding:10px; margin: 10px 0; max-height: 300px; overflow:auto;">';
			echo '<strong>Starting Manual Cleanup Diagnostics...</strong><br/>';
			
			$temp_dir = yourls_get_option('icc_upload_share_dir');
			if (!$temp_dir) $temp_dir = sys_get_temp_dir();
			
			echo "Target Directory: " . htmlspecialchars($temp_dir) . "<br/>";
			
			if (!is_dir($temp_dir)) {
				echo "<span style='color:red'>Directory does not exist!</span><br/>";
			} elseif (!is_writable($temp_dir)) {
				echo "<span style='color:red'>Directory is not writable! Permissions issues likely.</span><br/>";
			} else {
				$files = scandir($temp_dir);
				$found = 0;
				foreach ($files as $file) {
					if ($file == '.' || $file == '..') continue;
					
					// Only look for icc_temp_ folders
					if (strpos($file, 'icc_temp_') !== 0) continue;
					
					$path = rtrim($temp_dir, '/') . '/' . $file;
					if (!is_dir($path)) continue;
					
					$found++;
					$age = time() - filemtime($path);
					echo "<hr/><strong>Found:</strong> " . htmlspecialchars($file) . "<br/>";
					echo "Path: " . htmlspecialchars($path) . "<br/>";
					echo "Age: " . $age . " seconds (" . round($age/3600, 2) . " hours)<br/>";
					
					// Force delete if requested via manual action, or just standard check
					// For manual diagnostics, we'll try to delete anything > 24 hours just like the automated one
					if ($age > 86400) {
						echo "Status: <span style='color:blue'>Older than 24 hours. Attempting deletion...</span><br/>";
						icc_rrmdir($path);
						if (!file_exists($path)) {
							echo "Result: <span style='color:green'>DELETED SUCCESS.</span><br/>";
						} else {
							echo "Result: <span style='color:red'>DELETED FAILED. Check server log/permissions.</span><br/>";
						}
					} else {
						echo "Status: <span style='color:orange'>Kept (Not old enough).</span><br/>";
					}
				}
				
				if ($found == 0) {
					echo "No temporary 'icc_temp_' folders found.<br/>";
				}
			}
			echo '<strong>Diagnostics Complete.</strong></div>';
		} else {
			echo "<div class='error'>Security check failed.</div>";
		}
	}

	$message = '';
	if (isset($_POST['submit']) && $_POST['submit'] == 'Upload')
		$message = icc_upload_and_shorten_process_upload();

	$storage_type = yourls_get_option('icc_upload_storage_type', 'local');
	$share_url = yourls_get_option('icc_upload_share_url');
	$share_dir = yourls_get_option('icc_upload_share_dir');
	$suffix_length = yourls_get_option('icc_upload_suffix_length', 4);

	// S3 Config
	$s3_key = yourls_get_option('icc_upload_s3_key');
	$s3_secret = yourls_get_option('icc_upload_s3_secret');
	$s3_region = yourls_get_option('icc_upload_s3_region');
	$s3_bucket = yourls_get_option('icc_upload_s3_bucket');
	$s3_disable_acl = yourls_get_option('icc_upload_s3_disable_acl', false);

	// input form
	echo '
	<h2>Upload & Shorten</h2>
	<h3>Send a file to ' . ($storage_type == 's3' ? 'AWS S3' : 'your webserver') . ' and create a short-URL for it.</h3>';

	// Limits Diagnostics
	$max_upload = ini_get('upload_max_filesize');
	$max_post = ini_get('post_max_size');
	echo "<p><small>Server Limits: Upload Max Filesize: <strong>$max_upload</strong>, Post Max Size: <strong>$max_post</strong>. <br>The <strong>Smart Uploader</strong> bypasses these limits by splitting files into chunks!</small></p>";

	if (!empty($message)) {
		echo "<p><strong>$message</strong></p>";
	}

	if (
		($storage_type == 'local' && (empty($share_url) || empty($share_dir))) ||
		($storage_type == 's3' && (empty($s3_key) || empty($s3_secret) || empty($s3_region) || empty($s3_bucket)))
	) {
		echo '<p style="color:red"><strong>Please configure the plugin below before using this plugin.</strong></p>';
	}

	$chunk_nonce = yourls_create_nonce('icc_upload_chunk');

	echo '
	<form id="icc_upload_form" method="post" enctype="multipart/form-data"> 
	<input type="hidden" name="action" value="upload_file" />
    <input type="hidden" id="chunk_nonce" value="' . $chunk_nonce . '" />
    
	<fieldset> <legend>Select a file </legend>
	<p><input type="file" id="file_upload" name="file_upload" /></p>
    
    <div id="progress_container" style="display:none; width: 100%; background-color: #f3f3f3; border: 1px solid #ccc; margin-top: 10px;">
        <div id="progress_bar" style="width: 0%; height: 20px; background-color: #4caf50; text-align: center; color: white;">0%</div>
    </div>
    <p id="upload_status"></p>
    
	</fieldset>';

	// YOURLS options
	echo '
	<fieldset> <legend>YOURLS database options</legend>

		<p><label for="custom_shortname">Custom shortname: </label> 
		<input type="text" id="custom_shortname" name="custom_shortname" />
	
		<label for="custom_title">Custom title: </label> 
		<input type="text" id="custom_title" name="custom_title" /></p>
	</fieldset>';

	// filename handling
	echo '
	<fieldset> <legend>Filename conversions (optional)</legend>

		<p><input type="radio" id="safe_filename" name="convert_filename" value="browser-safe" checked="checked" />
		<label for="safe_filename">Browser-safe filename </label> 
		<small>(Recommended if the file should be accessed by web-browsers.)<br/ >
		Ex.: "my not safe&clean filename #1.txt" -> https://example.com/my_not_safe_clean_filename_1.txt </small></p>
        
        <p><input type="radio" id="safe_suffix" name="convert_filename" value="safe_suffix" />
		<label for="safe_suffix">Browser-safe filename + random suffix </label> 
		<small>(Adds a random alphanumeric suffix to the filename.)<br/ >
		Ex.: "file.txt" -> https://example.com/file_a1b2.txt </small></p>

		<p><input type="radio" id="random_filename" name="convert_filename" value="randomized" />
		<label for="random_filename">Randomize filename </label> 
		<small>(Browser-safe filenames with a slight protection against systematic crawling your web-directory.)<br/ >
		Ex.: "mypicture.jpg" -> https://example.com/9a3e97434689.jpg </small></p>

	</fieldset>';

	// do it!
	echo '	
	<p><input type="submit" id="submit_btn" name="submit" value="Upload" /></p>
	</form>';

	// JS for Chunked Upload
	echo '
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var form = document.getElementById("icc_upload_form");
        var fileInput = document.getElementById("file_upload");
        var progressBar = document.getElementById("progress_bar");
        var progressContainer = document.getElementById("progress_container");
        var status = document.getElementById("upload_status");
        var submitBtn = document.getElementById("submit_btn");

        form.onsubmit = function(event) {
            if (fileInput.files.length === 0) return;
            
            // Only capture if file is selected
            event.preventDefault();
            
            var file = fileInput.files[0];
            var chunkSize = 2 * 1024 * 1024; // 2MB
            var totalChunks = Math.ceil(file.size / chunkSize);
            var chunkIndex = 0;
            var uploadId = Date.now() + "_" + Math.random().toString(36).substr(2, 9);
            var nonce = document.getElementById("chunk_nonce").value;
            
            progressContainer.style.display = "block";
            submitBtn.disabled = true;
            status.innerHTML = "Uploading chunk 1 of " + totalChunks + "...";
            
            function uploadNextChunk() {
                var start = chunkIndex * chunkSize;
                var end = Math.min(start + chunkSize, file.size);
                var chunk = file.slice(start, end);
                
                var formData = new FormData();
                formData.append("action", "icc_upload_chunk");
                formData.append("file_chunk", chunk);
                formData.append("chunk_index", chunkIndex);
                formData.append("upload_id", uploadId);
                formData.append("nonce", nonce);
                
                var xhr = new XMLHttpRequest();
                xhr.open("POST", window.location.href, true);
                
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.status === "success") {
                                chunkIndex++;
                                var percent = Math.round((chunkIndex / totalChunks) * 100);
                                progressBar.style.width = percent + "%";
                                progressBar.innerText = percent + "%";
                                
                                if (chunkIndex < totalChunks) {
                                    status.innerHTML = "Uploading chunk " + (chunkIndex + 1) + " of " + totalChunks + "...";
                                    uploadNextChunk();
                                } else {
                                    status.innerHTML = "Refining upload...";
                                    finishUpload();
                                }
                            } else {
                                status.innerHTML = "Error: " + resp.message;
                                submitBtn.disabled = false;
                            }
                        } catch(e) {
                            status.innerHTML = "Error parsing server response.";
                            submitBtn.disabled = false;
                        }
                    } else {
                        status.innerHTML = "Server error " + xhr.status;
                        submitBtn.disabled = false;
                    }
                };
                
                xhr.send(formData);
            }
            
            function finishUpload() {
                var formData = new FormData(); 
                // Append all form fields except the file input
                var elements = form.elements;
                for (var i = 0; i < elements.length; i++) {
                    var el = elements[i];
                    if (el.name && el.type !== \'file\' && el.name !== \'submit\') { // Skip file and submit button
                        if (el.type === \'radio\' || el.type === \'checkbox\') {
                            if (el.checked) formData.append(el.name, el.value);
                        } else {
                            formData.append(el.name, el.value);
                        }
                    }
                }

                formData.append("action", "icc_upload_finish");
                formData.append("upload_id", uploadId);
                formData.append("file_name", file.name);
                formData.append("nonce", nonce);
                
                var xhr = new XMLHttpRequest();
                xhr.open("POST", window.location.href, true);
                xhr.onload = function() {
                     submitBtn.disabled = false;
                     if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.status === "success") {
                                status.innerHTML = "Upload Complete!";
                                // Instead of redirecting, we replace body or show message
                                // Ideally, we reload or show the result HTML
                                var resultDiv = document.createElement("div");
                                resultDiv.innerHTML = resp.message;
                                form.parentNode.insertBefore(resultDiv, form);
                                form.reset();
                                progressContainer.style.display = "none";
                            } else {
                                status.innerHTML = "Error finishing upload: " + resp.message;
                            }
                        } catch(e) {
                             status.innerHTML = "Error finishing upload (Invalid JSON).";
                             console.log(xhr.responseText);
                        }
                     }
                };
                xhr.send(formData);
            }
            
            uploadNextChunk();
        };
    });
    </script>
    ';

	// File Manager
	if ($storage_type == 's3' && !empty($s3_key) && !empty($s3_secret) && !empty($s3_bucket)) {
		icc_upload_and_shorten_file_manager($s3_key, $s3_secret, $s3_region, $s3_bucket);
	} elseif ($storage_type == 'local' && !empty($share_dir)) {
		icc_upload_and_shorten_local_file_manager($share_dir, $share_url);
	}

	// Configuration Section
	$nonce = yourls_create_nonce('icc_upload_and_shorten_settings');
	echo '
    <hr />
    <h3>Configuration</h3>
    <form method="post">
    <input type="hidden" name="action" value="icc_upload_and_shorten_save" />
    <input type="hidden" name="nonce" value="' . $nonce . '" />
    
    <p>
        <label for="icc_upload_storage_type"><strong>Storage Type:</strong></label><br />
        <select name="icc_upload_storage_type" id="icc_upload_storage_type">
            <option value="local" ' . ($storage_type == 'local' ? 'selected' : '') . '>Local Server</option>
            <option value="s3" ' . ($storage_type == 's3' ? 'selected' : '') . '>AWS S3</option>
        </select>
    </p>
    
    <h4>Local Server Settings</h4>
    <p>
        <label for="icc_upload_share_url">Share URL (The web URL path where YOURLS short-links will redirect to):</label><br />
        <small>Example: <code>https://example.com/file/</code></small><br />
        <input type="text" id="icc_upload_share_url" name="icc_upload_share_url" value="' . $share_url . '" size="50" />
    </p>
    <p>
        <label for="icc_upload_share_dir">Share Directory (The physical path where uploads are stored):</label><br />
        <small>Example: <code>/home/username/htdocs/example.com/file/</code> (Directory must exist)</small><br />
        <input type="text" id="icc_upload_share_dir" name="icc_upload_share_dir" value="' . $share_dir . '" size="50" />
    </p>
    
    <h4>AWS S3 Settings</h4>
    <p>
        <label for="icc_upload_s3_key">AWS Access Key:</label><br />
        <input type="text" id="icc_upload_s3_key" name="icc_upload_s3_key" value="' . $s3_key . '" size="50" />
    </p>
    <p>
        <label for="icc_upload_s3_secret">AWS Secret Key:</label><br />
        <input type="password" id="icc_upload_s3_secret" name="icc_upload_s3_secret" value="' . $s3_secret . '" size="50" />
    </p>
    <p>
        <label for="icc_upload_s3_region">AWS Region:</label><br />
        <input type="text" id="icc_upload_s3_region" name="icc_upload_s3_region" value="' . $s3_region . '" size="20" placeholder="us-east-1" />
    </p>
    <p>
        <label for="icc_upload_s3_bucket">S3 Bucket Name:</label><br />
        <input type="text" id="icc_upload_s3_bucket" name="icc_upload_s3_bucket" value="' . $s3_bucket . '" size="50" />
    </p>
    <p>
        <input type="checkbox" id="icc_upload_s3_disable_acl" name="icc_upload_s3_disable_acl" ' . ($s3_disable_acl ? 'checked' : '') . ' />
        <label for="icc_upload_s3_disable_acl"><strong>Disable ACLs</strong> (Check this if your bucket has "Block public access" or "Bucket Owner Enforced" enabled)</label>
    </p>
    
    <h4>General Settings</h4>
    <p>
        <label for="icc_upload_suffix_length">Random Suffix Length (For "Browser-safe + random suffix" option):</label><br />
        <input type="number" id="icc_upload_suffix_length" name="icc_upload_suffix_length" value="' . $suffix_length . '" min="1" max="32" />
    </p>

    <p>
        <label><strong>Diagnostics:</strong></label><br />
        <a href="javascript:void(0);" onclick="document.getElementById(\'icc_cleanup_form\').submit();" class="button">Run Cleanup & Diagnostics Now</a>
        <small> (Checks for \'icc_temp_\' folders older than 24 hours and attempts to delete them)</small>
    </p>
    
    <p><input type="submit" value="Save Configuration" class="button-primary" /></p>
    </form>
    
    <form id="icc_cleanup_form" method="post" style="display:none;">
        <input type="hidden" name="action" value="icc_manual_cleanup" />
        <input type="hidden" name="nonce" value="' . yourls_create_nonce('icc_manual_cleanup') . '" />
    </form>
    ';

	// footer
	echo '

		<hr style="margin-top: 40px" />
<p><strong><a href="https://ivancarlos.me/" target="_blank">Ivan Carlos</a></strong>  &raquo; 
<a href="https://buymeacoffee.com/ivancarlos" target="_blank">Buy Me a Coffee</a></p>';
}

function icc_upload_and_shorten_update_settings()
{
	yourls_verify_nonce('icc_upload_and_shorten_settings', $_REQUEST['nonce']);

	if (isset($_POST['icc_upload_storage_type']))
		yourls_update_option('icc_upload_storage_type', $_POST['icc_upload_storage_type']);

	if (isset($_POST['icc_upload_share_url']))
		yourls_update_option('icc_upload_share_url', rtrim($_POST['icc_upload_share_url'], '/') . '/');

	if (isset($_POST['icc_upload_share_dir']))
		yourls_update_option('icc_upload_share_dir', rtrim($_POST['icc_upload_share_dir'], '/') . '/');

	if (isset($_POST['icc_upload_s3_key']))
		yourls_update_option('icc_upload_s3_key', trim($_POST['icc_upload_s3_key']));
	if (isset($_POST['icc_upload_s3_secret']))
		yourls_update_option('icc_upload_s3_secret', trim($_POST['icc_upload_s3_secret']));
	if (isset($_POST['icc_upload_s3_region']))
		yourls_update_option('icc_upload_s3_region', trim($_POST['icc_upload_s3_region']));
	if (isset($_POST['icc_upload_s3_bucket']))
		yourls_update_option('icc_upload_s3_bucket', trim($_POST['icc_upload_s3_bucket']));

	if (isset($_POST['icc_upload_s3_disable_acl'])) {
		yourls_update_option('icc_upload_s3_disable_acl', true);
	} else {
		yourls_update_option('icc_upload_s3_disable_acl', false);
	}

	if (isset($_POST['icc_upload_suffix_length']))
		yourls_update_option('icc_upload_suffix_length', intval($_POST['icc_upload_suffix_length']));

	echo "<div class='updated'>Settings saved</div>";
}

// Local File Manager Function
function icc_upload_and_shorten_local_file_manager($dir, $url)
{
	echo '<hr />';
	echo '<h3>Local File Manager</h3>';

	if (!is_dir($dir)) {
		echo '<p style="color:red">Directory not found: ' . htmlspecialchars($dir) . '</p>';
		return;
	}

	$raw_files = scandir($dir);
	$files = [];
	foreach ($raw_files as $f) {
		if ($f == '.' || $f == '..')
			continue;
		$full_path = rtrim($dir, '/') . '/' . $f;
		// Exclude directories (like the temp ones if they exist)
		if (!is_dir($full_path)) {
			$files[] = $f;
		}
	}

	// Sort by modification time (Newest first)
	usort($files, function ($a, $b) use ($dir) {
		return filemtime(rtrim($dir, '/') . '/' . $b) - filemtime(rtrim($dir, '/') . '/' . $a);
	});

	// $files = array_values($files); // Already indexed 0..n by sorting

	// Pagination
	$per_page = 20;
	$total_files = count($files);
	$total_pages = ceil($total_files / $per_page);
	$current_page = isset($_GET['local_page']) ? max(1, intval($_GET['local_page'])) : 1;
	$offset = ($current_page - 1) * $per_page;

	$page_files = array_slice($files, $offset, $per_page);

	if (empty($page_files)) {
		echo '<p>No files found.</p>';
	} else {
		$nonce = yourls_create_nonce('icc_delete_local_file');
		echo '<table class="widefat" style="margin-top:10px;">';
		echo '<thead><tr><th>File Name</th><th>Size</th><th>Last Modified</th><th>Action</th></tr></thead>';
		echo '<tbody>';

		foreach ($page_files as $file) {
			$filepath = rtrim($dir, '/') . '/' . $file;
			$size = file_exists($filepath) ? round(filesize($filepath) / 1024, 2) . ' KB' : 'N/A';
			$date = file_exists($filepath) ? date("Y-m-d H:i:s", filemtime($filepath)) : 'N/A';
			$file_url = rtrim($url, '/') . '/' . $file;

			echo '<tr>';
			echo '<td><a href="' . htmlspecialchars($file_url) . '" target="_blank">' . htmlspecialchars($file) . '</a></td>';
			echo '<td>' . $size . '</td>';
			echo '<td>' . $date . '</td>';
			echo '<td>';
			echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Are you sure you want to delete ' . htmlspecialchars($file, ENT_QUOTES) . '?\');">';
			echo '<input type="hidden" name="action" value="delete_local_file" />';
			echo '<input type="hidden" name="file_name" value="' . htmlspecialchars($file) . '" />';
			echo '<input type="hidden" name="nonce" value="' . $nonce . '" />';
			echo '<input type="submit" value="Delete" class="button-secondary" />';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody>';
		echo '</table>';

		// Pagination Controls
		if ($total_pages > 1) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo '<span class="displaying-num">' . $total_files . ' items</span>';
			$url = 'plugins.php?page=icc_upload_and_shorten';

			if ($current_page > 1) {
				echo '<a class="button" href="' . $url . '&local_page=1">&laquo; First</a> ';
				echo '<a class="button" href="' . $url . '&local_page=' . ($current_page - 1) . '">&lsaquo; Previous</a> ';
			}

			echo '<span class="current-page"> Page ' . $current_page . ' of ' . $total_pages . ' </span>';

			if ($current_page < $total_pages) {
				echo ' <a class="button" href="' . $url . '&local_page=' . ($current_page + 1) . '">Next &rsaquo;</a> ';
				echo '<a class="button" href="' . $url . '&local_page=' . $total_pages . '">Last &raquo;</a>';
			}
			echo '</div></div>';
		}
	}

}

// Recursive directory removal
function icc_rrmdir($dir)
{
	if (is_dir($dir)) {
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				if (is_dir($dir . "/" . $object) && !is_link($dir . "/" . $object))
					icc_rrmdir($dir . "/" . $object);
				else
					unlink($dir . "/" . $object);
			}
		}
		rmdir($dir);
	}
}

// Cleanup Temp Folders
function icc_upload_and_shorten_cleanup_temp()
{
	$temp_dir = yourls_get_option('icc_upload_share_dir');
	if (!$temp_dir)
		$temp_dir = sys_get_temp_dir();

	if (!is_dir($temp_dir))
		return;

	// Scan for icc_temp_* directories
	$files = scandir($temp_dir);
	foreach ($files as $file) {
		if ($file == '.' || $file == '..')
			continue;

		$path = rtrim($temp_dir, '/') . '/' . $file;
		if (is_dir($path) && strpos($file, 'icc_temp_') === 0) {
			// Check age (1 hour = 3600 seconds)
			if (filemtime($path) < (time() - 86400)) {
				icc_rrmdir($path);
			}
		}
	}
}

// Check for AWS SDK
function icc_get_aws_client($key, $secret, $region)
{
	if (!file_exists(dirname(__FILE__) . '/aws.phar')) {
		return false;
	}
	require_once dirname(__FILE__) . '/aws.phar';

	try {
		$s3 = new Aws\S3\S3Client([
			'version' => 'latest',
			'region' => $region,
			'credentials' => [
				'key' => $key,
				'secret' => $secret,
			],
		]);
		return $s3;
	} catch (Exception $e) {
		return false;
	}
}

// S3 File Manager Function
function icc_upload_and_shorten_file_manager($key, $secret, $region, $bucket)
{
	echo '<hr />';
	echo '<h3>S3 File Manager</h3>';

	$s3 = icc_get_aws_client($key, $secret, $region);
	if (!$s3) {
		echo '<p style="color:red">Failed to initialize AWS Client.</p>';
		return;
	}

	// Pagination
	$continuation_token = isset($_GET['s3_next_token']) ? $_GET['s3_next_token'] : null;

	try {
		$params = [
			'Bucket' => $bucket,
			'MaxKeys' => 20
		];

		if ($continuation_token) {
			$params['ContinuationToken'] = $continuation_token;
		}

		$objects = $s3->listObjectsV2($params);

		if (!isset($objects['Contents']) || empty($objects['Contents'])) {
			echo '<p>No files found in bucket.</p>';
			if ($continuation_token) {
				echo '<p><a href="plugins.php?page=icc_upload_and_shorten" class="button">Start Over</a></p>';
			}
		} else {
			$nonce = yourls_create_nonce('icc_delete_file');
			echo '<table class="widefat" style="margin-top:10px;">';
			echo '<thead><tr><th>File Name</th><th>Size</th><th>Last Modified</th><th>Action</th></tr></thead>';
			echo '<tbody>';
			foreach ($objects['Contents'] as $object) {
				// Construct the file URL (Path-style S3 URL format)
				$file_url = "https://s3.{$region}.amazonaws.com/{$bucket}/" . $object['Key'];

				echo '<tr>';
				echo '<td><a href="' . htmlspecialchars($file_url) . '" target="_blank">' . htmlspecialchars($object['Key']) . '</a></td>';
				echo '<td>' . round($object['Size'] / 1024, 2) . ' KB</td>';
				echo '<td>' . $object['LastModified'] . '</td>';
				echo '<td>';
				echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Are you sure you want to delete ' . htmlspecialchars($object['Key'], ENT_QUOTES) . '?\');">';
				echo '<input type="hidden" name="action" value="delete_file" />';
				echo '<input type="hidden" name="file_key" value="' . htmlspecialchars($object['Key']) . '" />';
				echo '<input type="hidden" name="nonce" value="' . $nonce . '" />';
				echo '<input type="submit" value="Delete" class="button-secondary" />';
				echo '</form>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody>';
			echo '</table>';
			echo '<p><small>Showing files from S3 bucket.</small></p>';

			// Pagination History (to allow 'Previous')
			$history_raw = isset($_GET['s3_history']) ? $_GET['s3_history'] : '';
			$history = $history_raw ? explode(',', $history_raw) : [];

			// Pagination Controls
			echo '<div class="tablenav"><div class="tablenav-pages">';
			$url_base = 'plugins.php?page=icc_upload_and_shorten';

			// First Page
			if ($continuation_token) {
				echo '<a class="button" href="' . $url_base . '">&laquo; First</a> ';
			}

			// Previous Page
			if (!empty($history)) {
				$prev_token = array_pop($history);
				$prev_history = implode(',', $history);
				$prev_url = $url_base;
				if ($prev_token && $prev_token !== '__TOP__') {
					$prev_url .= '&s3_next_token=' . urlencode($prev_token);
				}
				if ($prev_history) {
					$prev_url .= '&s3_history=' . urlencode($prev_history);
				}
				echo '<a class="button" href="' . $prev_url . '">&lsaquo; Previous</a> ';
			}

			// Next Page
			if (isset($objects['NextContinuationToken'])) {
				$next_token = $objects['NextContinuationToken'];
				// Append current token to history
				$current_history = $history_raw;
				$token_to_add = $continuation_token ? $continuation_token : '__TOP__';
				if ($current_history) {
					$current_history .= ',' . $token_to_add;
				} else {
					$current_history = $token_to_add;
				}
				echo '<a class="next-page button" href="' . $url_base . '&s3_next_token=' . urlencode($next_token) . '&s3_history=' . urlencode($current_history) . '">Next &rsaquo;</a>';
			}
			echo '</div></div>';
		}
	} catch (Aws\S3\Exception\S3Exception $e) {
		echo '<p style="color:red">Error listing files: ' . $e->getMessage() . '</p>';
	}
}

// Update option in database
function icc_upload_and_shorten_process_upload($local_file_path = null, $original_filename = null)
{
	// If not coming from chunked upload, standard validations
	if (!$local_file_path) {
		// did the user select any file?
		if ($_FILES['file_upload']['error'] == UPLOAD_ERR_NO_FILE) {
			return 'You need to select a file to upload.';
		}
	}

	// Increase limits for processing large files
	set_time_limit(0);

	$storage_type = yourls_get_option('icc_upload_storage_type', 'local');

	// Check Config
	if ($storage_type == 'local') {
		$my_url = yourls_get_option('icc_upload_share_url');
		$my_uploaddir = yourls_get_option('icc_upload_share_dir');
		if (empty($my_url) || empty($my_uploaddir))
			return 'Plugin not configured for local storage.';

		// Check if directory exists and is writable
		if (!is_dir($my_uploaddir) || !is_writable($my_uploaddir)) {
			return 'Upload directory does not exist or is not writable: ' . $my_uploaddir;
		}
	} elseif ($storage_type == 's3') {
		$key = yourls_get_option('icc_upload_s3_key');
		$secret = yourls_get_option('icc_upload_s3_secret');
		$region = yourls_get_option('icc_upload_s3_region');
		$bucket = yourls_get_option('icc_upload_s3_bucket');
		$disable_acl = yourls_get_option('icc_upload_s3_disable_acl', false);
		if (empty($key) || empty($secret) || empty($region) || empty($bucket))
			return 'Plugin not configured for S3 storage.';

		$s3 = icc_get_aws_client($key, $secret, $region);
		if (!$s3)
			return 'AWS SDK not found or failed to initialize, please ensure aws.phar is in the plugin folder.';
	}

	$file_name_to_use = $local_file_path ? $original_filename : $_FILES['file_upload']['name'];

	// Handle the filename's extension
	$my_upload_extension = pathinfo($file_name_to_use, PATHINFO_EXTENSION);

	// If there is any extension at all then append it with a leading dot
	$my_extension = '';
	if (isset($my_upload_extension) && $my_upload_extension != NULL) {
		$my_extension = '.' . $my_upload_extension;
	}

	$my_upload_filename = pathinfo($file_name_to_use, PATHINFO_FILENAME);
	$my_filename = $my_upload_filename; // Default

	if (isset($_POST['convert_filename'])) {
		switch ($_POST['convert_filename']) {
			case 'browser-safe': {
				// make the filename web-safe: 
				$my_filename_trim = trim($my_upload_filename);
				$my_filename_trim = strtolower($my_filename_trim); // Force lowercase
				$my_extension = strtolower($my_extension);
				$my_RemoveChars = array("([^()_\-\.,0-9a-zA-Z\[\]])");	// replace what's NOT in here!
				$my_filename = preg_replace($my_RemoveChars, "_", $my_filename_trim);
				$my_filename = preg_replace("(_{2,})", "_", $my_filename);
				$my_extension = preg_replace($my_RemoveChars, "_", $my_extension);
				$my_extension = preg_replace("(_{2,})", "_", $my_extension);
			}
				break;

			case 'safe_suffix': {
				// browser-safe + random suffix
				$my_filename_trim = trim($my_upload_filename);
				$my_filename_trim = strtolower($my_filename_trim); // Force lowercase
				$my_extension = strtolower($my_extension);
				$my_RemoveChars = array("([^()_\-\.,0-9a-zA-Z\[\]])");
				$my_filename = preg_replace($my_RemoveChars, "_", $my_filename_trim);
				$my_filename = preg_replace("(_{2,})", "_", $my_filename);
				$my_extension = preg_replace($my_RemoveChars, "_", $my_extension);
				$my_extension = preg_replace("(_{2,})", "_", $my_extension);

				$suffix_length = yourls_get_option('icc_upload_suffix_length', 4);
				$suffix = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $suffix_length);
				$my_filename .= '_' . $suffix;
			}
				break;

			case 'randomized': {
				// make up a random name for the uploaded file
				$my_filename = substr(md5($my_upload_filename . strtotime("now")), 0, 12);
			}
				break;
		}
	}

	// avoid duplicate filenames
	if ($storage_type == 'local') {
		$my_count = 2;
		$my_path = $my_uploaddir . $my_filename . $my_extension;
		$my_final_file_name = $my_filename . $my_extension;

		while (file_exists($my_path)) {
			$my_path = $my_uploaddir . $my_filename . '.' . $my_count . $my_extension;
			$my_final_file_name = $my_filename . '.' . $my_count . $my_extension;
			$my_count++;
		}
	} else {
		// For S3, exact duplicate check is hard without API call, so we assume timestamp or suffix makes it unique enough
		// Or we can just overwrite as S3 versioning might be on, but user asked for simple upload
		// We will just use the name derived.
		$my_final_file_name = $my_filename . $my_extension;

		// If we are processing a chunked upload, source is the assembled file
		// If it's a standard upload, it's the temp file
		$my_path = $local_file_path ? $local_file_path : $_FILES['file_upload']['tmp_name'];
	}

	$my_upload_fullname = pathinfo($file_name_to_use, PATHINFO_BASENAME);

	// Upload Logic
	$upload_success = false;

	if ($storage_type == 'local') {
		// If local file path provided (Chunked), rename it to destination
		if ($local_file_path) {
			if (rename($local_file_path, $my_path)) {
				$upload_success = true;
				$final_url = $my_url . $my_final_file_name;
			}
		} else {
			if (move_uploaded_file($_FILES['file_upload']['tmp_name'], $my_path)) {
				$upload_success = true;
				$final_url = $my_url . $my_final_file_name;
			}
		}
	} elseif ($storage_type == 's3') {
		try {
			$args = [
				'Bucket' => $bucket,
				'Key' => $my_final_file_name,
				'SourceFile' => $my_path,
			];

			if (!$disable_acl) {
				$args['ACL'] = 'public-read';
			}

			$result = $s3->putObject($args);

			// Cleanup temp file if it was a chunked upload
			if ($local_file_path && file_exists($local_file_path)) {
				unlink($local_file_path);
			}

			// Use S3 Object URL directly
			$final_url = $result['ObjectURL'];
			$upload_success = true;
		} catch (Aws\S3\Exception\S3Exception $e) {
			return '<font color="red">S3 Upload failed: ' . $e->getMessage() . '</font>';
		}
	}

	if ($upload_success) {
		// On success:
		// obey custom shortname, if given:
		$my_custom_shortname = '';
		if (isset($_POST['custom_shortname']) && $_POST['custom_shortname'] != NULL) {
			$my_custom_shortname = $_POST['custom_shortname'];
		}
		// change custom title, if given. Default is original filename, but if user provided one, use it:
		$my_custom_title = $_POST['convert_filename'] . ': ' . $my_upload_fullname;
		if (isset($_POST['custom_title']) && $_POST['custom_title'] != NULL) {
			$my_custom_title = $_POST['custom_title'];
		}

		// let YOURLS create the link:
		$my_short_url = yourls_add_new_link($final_url, $my_custom_shortname, $my_custom_title);

		return '<font color="green">"' . $my_upload_fullname . '" successfully sent to ' . ($storage_type == 's3' ? 'S3' : 'Server') . '. Links:</font><br />' .
			'Direct: <a href="' . $final_url . '" target="_blank">' . $final_url . '</a><br />' .
			'Short:  <a href="' . $my_short_url['shorturl'] . '" target="_blank">' . $my_short_url['shorturl'] . '</a>';
	} else {
		$error = isset($_FILES['file_upload']) ? $_FILES['file_upload']['error'] : 'Unknown error';
		return '<font color="red">Upload failed, sorry! The error was ' . $error . '</font>';
	}
}
