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
    header('Location: /access-denied.html'); // Adjust the path to your Drupal installation
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
        margin-top: 20px;
    }

    /* Spinner animation */
	.spinner {
        border: 16px solid #f3f3f3;
        border-top: 16px solid #3498db;
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
        display: inline-block;
        background-color: #001b6b;
        color: #ffffff;
        padding: 0.5rem 1.2rem;
        border: 0;
        border-radius: 8px;
        transition: all 0.4s ease-in-out;
        cursor: pointer;
    }

    .button:hover {
        background-color: #e9ab0d;
    }

    hr {
        border-top: 1px dashed #4099f7;
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
    <div id="loadingSpinner" style="display: none; flex-direction: column; align-items: center;">
    <div class="spinner">
        <div class="loading-text">Analyzing...</div>
    </div>
    <div id="progressContainer" style="width: 80%; margin-top: 20px;">
        <div id="progressBar" style="width: 0%; height: 20px; background-color: #3498db; border-radius: 5px;"></div>
    </div>
</div>

    <script>
    document.getElementById('uploadForm').addEventListener('submit', function(event) {
        event.preventDefault();

        const formData = new FormData(this);
        const loadingSpinner = document.getElementById('loadingSpinner');
        const responseDiv = document.getElementById('response');
		const progressBar = document.getElementById('progressBar');

        // Show the loading spinner and reset progress bar
		loadingSpinner.style.display = 'flex';
    	responseDiv.innerHTML = '';
    	progressBar.style.width = '0%';
		
		 // Simulate progress increment over time
    	let progress = 0;
    	const progressInterval = setInterval(() => {
        if (progress < 80) {  // Simulate progress up to 80%
            progress += 10;
            progressBar.style.width = `${progress}%`;
      	  }
    	}, 500);

        fetch('upload.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
			 // Hide the loading spinner
            loadingSpinner.style.display = 'none';
			
			// Clear interval and set progress to 100% on completion
        	clearInterval(progressInterval);
     	   	progressBar.style.width = '100%';
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

                if (data.pdf_path) {
                    const downloadButton = document.createElement('button');
                    downloadButton.className = 'button';
                    downloadButton.innerText = 'Download PDF';
                    downloadButton.onclick = () => {
                        window.open(data.pdf_path, '_blank');
                    };
                    responseDiv.appendChild(downloadButton);
                }
            }
        })
        .catch(error => {
			clearInterval(progressInterval);
            loadingSpinner.style.display = 'none';
			progressBar.style.width = '0%';
            console.error('Error:', error);
            responseDiv.innerHTML = "An error occurred during the analysis.";
        });
    });
    </script>
</body>
</html>
