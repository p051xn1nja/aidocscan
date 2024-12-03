<?php
set_time_limit(300);

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

// Load the autoloader and Drupal Kernel
$autoloader = require_once 'vendor/autoload.php';
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

// Directory to store the upload flags
$upload_flag_dir = 'upload_flags'; // Change this to your preferred directory path
$uid = $currentUser->id();
$upload_flag_file = $upload_flag_dir . '/user_' . $uid . '.txt';

// Check if the user has already uploaded a file
if (file_exists($upload_flag_file)) {
    echo json_encode(['error' => 'You have already uploaded a file.']);
    exit;
}

// Ensure the directory exists for the upload flags
if (!is_dir($upload_flag_dir)) {
    if (!mkdir($upload_flag_dir, 0755, true)) {
        echo json_encode(['error' => 'Failed to create directory for upload flags.']);
        exit;
    }
}

// Load required libraries for handling .docx and .pdf files
require 'vendor/autoload.php';

$openai_api_key = 'Open_AI_Key'; // Replace with your OpenAI API key

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// Check if a file is uploaded and if there are any errors
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'File upload error. Please try again.']);
        exit;
    }

    $file = $_FILES['document'];
    $file_type = $file['type'];
    $file_size = $file['size'];

	// Allowed file types and size limit (2MB)
    $allowed_types = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $max_file_size = 2 * 1024 * 1024;
	
    // Validate file type and size
	if (!in_array($file_type, $allowed_types)) {
        echo json_encode(['error' => 'Invalid file type. Please upload a PDF or DOCX file.']);
        exit;
    }

    if ($file_size > $max_file_size) {
        echo json_encode(['error' => 'File size exceeded. Please upload a file smaller than 2MB.']);
        exit;
    }

    // Extract text from the uploaded file
	$extracted_text = '';
    try {
        if ($file_type === 'application/pdf') {
            $parser = new Parser();
            $pdf = $parser->parseFile($file['tmp_name']);
            $extracted_text = $pdf->getText();
        } elseif ($file_type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            $phpWord = IOFactory::load($file['tmp_name']);
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText')) {
                        $extracted_text .= $element->getText() . "\n";
                    }
                }
            }
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Error extracting text from the document: ' . $e->getMessage()]);
        exit;
    }

    if (empty($extracted_text)) {
        echo json_encode(['error' => 'Failed to extract text from the document.']);
        exit;
    }

	// Define criteria to be analyzed by OpenAI
    $criteria_list = [
        "1. Should include and Introduction or Background – Short description of the organization or institution (who, what, why, where, how) and what the document covers.",
        "2. Must include a Policy Statement. This means a general statement (of the organization's commitment to quality. It states a commitment to customer requirements and the requirements of the standard. It also contains a pledge to work toward continual improvement).",
        "3. Must contain a vision statement and mission statement. Vision must be future oriented and concise. Mission must answer to the following questions: Who, What, Where, When, Why",
	"4. Should include core values of the organization.",
	"5. Should include Guiding Principles (eg, Systems Approach to Training, Compliance to NATO policies, doctrines and
directives, Accountability, Transparency, Continuous Improvement, Cooperation, High Quality Products.",
        "6. Should include the documents of Quality Management System as a reference (at least the titles).",
        "7. Should include Internal & External (e.g., SOPs and MoUs, NATO Policies and Regulations, Doctrine) or at list some list with the titles.",
        "8. Should include key documents - no need to detail everything - can describe products (courses) generically.",
        "9. Must include goals of the organization (strategic objectives) and/or objectives.",
        "10. Must include the aim of Quality Asurance Policy and/or the Purpose of Quality Asurance Policy Document.",
        "11. Must include the applicability of Quality Asurance Policy, namely who applicable to.",
        "12. Must include a list of key Stakeholders, at least two categories, internal and external.",
        "13. Must include quality process definition and description of key terminology (levels of quality): Inspection, Quality Control, Quality Assurance and Quality Management.",
        "14. Must include Roles and Responsibilities of Quality Management Team including Course Director, Course Admin, Officer with Primary Responsibilities, Training Breanch Chief Head, etc. Also must include Quality Management Team composition and organization diagram/chart.",
        "15. Should include a RACI Matrix",
        "16. Must include a Quality Assurance Review Cycle and/or Continuous Improvement Process, After Action Review, Post-Course Review, Curriculum Review Board, Annual QA (System/Institution) Internal Review, Annual QA Report, External re-accreditation.",
        "17. Must include Key Performance Indicators (KPIs) and how used to measure progress. Also how the KPIs are measured - thresholds for low values.",
        "18. Must include surveys for students and graduate feedback, for supervisors, instructors and also staff satisfaction.",
        "19. Must include instructor monitoring and assessment as well as course monitoring.",
        "20. Must include a description of procedures (SOP/SOI) review cycle",
        "21. Must include a description of the QA Policy review or update cycle",
        "22. Must include a student Assessment strategy, namely formative and/or summative including assessment criteria, e.g. student engagement criteria – outstanding to unsatisfactory.",
        "23. Must include graduation criteria (attendance + grades) – and if they have diffrent rules for course graduation certificates - Attendace and/or Graduation Certificates",
        "24. Must include a description of the student appeals process. Must include a detailed description on how the process is carried out, like the review and decission and/or final decission.",
        "25. Must include details about staff Management and recruitment, namely staff and/or instructor development (e.g. induction and/or orientation, initial training, continuous/further training, professional development, development plans).",
        "26. Must include descriptions about instructor monitoring, instructor assessment and/or evaluation, and course monitoring",
        "27. Must include description about definition and delivery of instruction, namely NATO Systems Approach to Training (SAT) (e.g., the five SAT Phases briefly describe Purpose/Process/Product) and also about Global Programming.",
        "28. Must include description about the conduct of Courses, namely scheduling and/or programming, resourcing, responsibilities, activity timelines for course delivery, etc.",
        "29. Must include a communication plan",
        "30. Must include a bried description about information systems and knowledge management.",
        "31. Must include a description about the public information process, namely briefly what and how (e.g., websites, social media, publications, etc.).",
        "32. Must include a relationship between teaching, research, doctrine development and Lessons Learned.",
        "33. Must include a Glossary or a list of definitions.",
        "34. Should include a list of SOPs."
    ];

	// Truncate the extracted text to ensure it doesn't exceed OpenAI's token limit
    $extracted_text = truncate_text($extracted_text, 4000); // Limit to approximately 4000 words
	
    $results = [];
    foreach ($criteria_list as $criterion) {
        $result = analyze_with_openai($extracted_text, $criterion, $openai_api_key);
        if ($result === false) {
            echo json_encode(['error' => 'Error contacting OpenAI.']);
            exit;
        }
        $results[] = [
            "criterion" => $criterion,
            "result" => $result
        ];
    }

    // After all checks are successful, create the flag file to mark that the user has uploaded a file
if (file_put_contents($upload_flag_file, "User $uid has uploaded a file.") === false) {
    echo json_encode(['error' => 'Failed to create upload flag file.']);
    exit;
}

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf($options);
	
	// Define your footer text
$footerText = "Document generated by NATO QA HUB on " . date("d-m-y @ H:i:s");

// Add footer styling to the HTML content
$pdfContent = '<style>
    @page {
        margin: 70px 50px 70px 50px; /* Top, right, bottom, left margins */
    }
    body {
        font-family: Arial, sans-serif;
    }
    .footer {
        position: fixed;
        bottom: -10px; /* Adjust this value as needed */
        left: 0;
        width: 100%;
        text-align: right;
        font-size: 10px;
        color: gray;
    }
</style>';

// Add main content with the footer element embedded
$pdfContent .= '<div><img src="data:image/png;base64,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" alt="QA Hub Logo"><h1>QA Policy Analysis Results</h1><hr>';
	
	foreach ($results as $result) {
    $color = 'black'; // Default color
    if (strtolower($result['result']) === 'met') {
        $color = 'green';
    } elseif (strtolower($result['result']) === 'partially met') {
        $color = 'orange';
    } elseif (strtolower($result['result']) === 'not met') {
        $color = 'red';
    }


    // Apply the color to the result text
    $pdfContent .= "<p><strong>{$result['criterion']}:</strong> <span style='color: {$color}; font-weight: bold;'>{$result['result']}</span></p>";
}

// Close the page-content div and add the footer directly inside
$pdfContent .= '</div><div class="footer">' . htmlspecialchars($footerText) . '</div>';
	
    $dompdf->loadHtml($pdfContent);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $outputDir = 'generated_pdfs';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    $pdfPath = "{$outputDir}/analysis_results_user_{$uid}.pdf";
    file_put_contents($pdfPath, $dompdf->output());

    echo json_encode([
        'results' => $results,
        'pdf_path' => '/' . $pdfPath
    ]);
}

// Function to truncate text to avoid exceeding OpenAI's token limits
function truncate_text($text, $max_words = 4000) {
    $words = explode(" ", $text);
    if (count($words) > $max_words) {
        return implode(" ", array_slice($words, 0, $max_words));
    }
    return $text;
}

// Function to interact with OpenAI API
function analyze_with_openai($text, $criterion, $api_key) {
    $ch = curl_init();
    $data = json_encode([
        "model" => "gpt-4o-mini",
        "messages" => [
            ["role" => "system", "content" => "You are a helpful assistant."],
            ["role" => "user", "content" => "Analyze the following text to determine if the criterion is met:\n\nCriterion: $criterion\n\nDocument Text: $text\n\nAnswer with one of the following words **only**: Met, Partially Met, Not Met. Do not provide any additional comments or explanations. Analyze the each criterion carefully and decide accurately. Pay attention to the KPI criterion. It there is no mention about them then criterion not met. If there are not well defined with thresholds then is partially met. If there is no list of key stakeholders then partially met."]
        ]
    ]);

    curl_setopt($ch, CURLOPT_URL, "https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $headers = [
        "Authorization: Bearer $api_key",
        "Content-Type: application/json"
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch) || $http_code !== 200) {
        error_log('OpenAI error: ' . curl_error($ch) . ' ' . $response);
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    $response_data = json_decode($response, true);
    return $response_data['choices'][0]['message']['content'] ?? false;
}
			
