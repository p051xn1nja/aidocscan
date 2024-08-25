<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

// Load the autoloader and Drupal Kernel
$autoloader = require_once 'vendor/autoload.php'; // Adjust the path to your Drupal installation
$request = Request::createFromGlobals();

// Boot the Drupal Kernel and handle the request
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$response = $kernel->handle($request);

// Get the current user
$currentUser = \Drupal::currentUser();

// Check if the user is authenticated
if ($currentUser->isAnonymous()) {
    // Redirect to the login page if the user is not authenticated
    header('Location: /access-denied.html');
    exit;
}

// If the user is authenticated, proceed with rendering the form
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analyze QA Policy Document</title>
	<style>
/* Ensure the loading spinner is below the response without centering it on the page */
#loadingSpinner {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-top: 20px; /* Adds space between the response and the spinner */
}

/* Spinner animation */
.spinner {
    border: 16px solid #f3f3f3; /* Light grey */
    border-top: 16px solid #3498db; /* Blue */
    border-radius: 50%;
    width: 100px;
    height: 100px;
    animation: spin 3s linear infinite;
    position: relative;
}

/* Keyframe animation for spinner */
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Position the loading text in the center of the spinner */
.loading-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 20px; /* Doubled the text size */
    color: #3498db;
    font-weight: bold;
}
		
.button {
    position: relative;
    display: inline-block;
    background-color: #001b6b; /* Dark blue */
    color: #ffffff;
    padding: 0.5rem 1.2rem;
    border: 0;
    border-radius: 8px;
    transition: all 0.4s ease-in-out;
	cursor: pointer;
}
		
.button:hover {
    background-color: #e9ab0d; /* Light orange */
}
		
hr {
  border-top: 1px dashed #4099f7; /* Light blue */
}

html * {
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
	color: #4a4a4a;
		}
		
.highlight:hover {
    font-weight: bold;
    color: #001b6b;
}
		
</style>
</head>
<body>
    <h2>Upload a PDF document to be analyzed by GPT-4 AI model.</h2>

    <form id="uploadForm" enctype="multipart/form-data" method="POST" action="upload.php">
        <label for="document">Choose a file to upload:</label>
        <input type="file" id="document" name="document" required class="button">(max. file size: 2MB)<br><br>

        <button class="button" type="submit">Upload and Analyze</button>
    </form>

    <h3>The results will be generated below:</h3><hr />
    <div id="response"></div>
	<div id="loadingSpinner" style="display: none;">
    <div class="spinner">
        <div class="loading-text">Analyzing...</div>
    </div>
</div>
    <!-- You can replace this with a more elaborate spinner if desired -->
</div>

    <script>
    document.getElementById('uploadForm').addEventListener('submit', function(event) {
        event.preventDefault();

        const formData = new FormData(this);
        const loadingSpinner = document.getElementById('loadingSpinner');
        const responseDiv = document.getElementById('response');

        // Show the loading spinner
        loadingSpinner.style.display = 'block';
        responseDiv.innerHTML = ''; // Clear previous response

        fetch('upload.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // Hide the loading spinner
            loadingSpinner.style.display = 'none';

            if (data.error) {
                responseDiv.innerHTML = `<p style="color: red;">${data.error}</p>`;
            } else {
                data.results.forEach(result => {
                    const resultElement = document.createElement('div');

                    // Set color based on the result
                    let color = 'black';
                    if (result.result.toLowerCase() === 'met') {
                        color = 'green';
                    } else if (result.result.toLowerCase() === 'partially met') {
                        color = 'orange';
                    } else if (result.result.toLowerCase() === 'not met') {
                        color = 'red';
                    }

                    resultElement.innerHTML = `<span class="highlight">${result.criterion}:</span> <span style="color: ${color}; font-weight: bold;">${result.result}<hr /></span>`;
                    responseDiv.appendChild(resultElement);
                });
            }
        })
        .catch(error => {
            // Hide the loading spinner
            loadingSpinner.style.display = 'none';
            console.error('Error:', error);
            responseDiv.innerHTML = "An error occurred during the analysis.";
        });
    });
</script>
</body>
</html>
