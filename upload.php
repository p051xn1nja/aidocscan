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

$openai_api_key = 'OPEN_AI_SECRET_KEY'; // Replace with your OpenAI API key

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

    $pdfContent = '<h1>QA Policy Analysis Results</h1><hr>';
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
    // foreach ($results as $result) {
      //  $pdfContent .= "<p><strong>{$result['criterion']}:</strong> {$result['result']}</p>";
    // }

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
