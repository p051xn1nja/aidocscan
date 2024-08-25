<?php

set_time_limit(300); // 300 seconds = 5 minutes

// Start session to store CSRF token and rate limiting
session_start();

// Load required libraries for handling .docx and .pdf files
require 'vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

// OpenAI API Key
$openai_api_key = 'YOUR_OPENAI_API_KEY_HERE'; // Replace with your OpenAI API key

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
    $max_file_size = 2 * 1024 * 1024; // 2MB

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
        "Should include and Introduction or Background – Short description of the organization or institution (who, what, why, where, how) and what the document covers.",
        "Must include a Policy Statement. This means a general statement (of the organization's commitment to quality. It states a commitment to customer requirements and the requirements of the standard. It also contains a pledge to work toward continual improvement).",
        "Must contain a vision statement and mission statement.",
		"Should include core values of the organization.",
		"Should include Guiding Principles (eg, Systems Approach to Training, Compliance to NATO policies, doctrines and
directives, Accountability, Transparency, Continuous Improvement, Cooperation, High Quality Products.",
        "Should include the documents of Quality Management System as a reference (at least the titles).",
        "Should include Internal & External (e.g., SOPs and MoUs, NATO Policies and Regulations, Doctrine) or at list some list with the titles.",
        "Should include key documents - no need to detail everything - can describe products (courses) generically.",
        "Must include goals of the organization (strategic objectives) and/or objectives.",
        "Must include the aim of Quality Asurance Policy and/or the Purpose of Quality Asurance Policy Document.",
        "Must include the applicability of Quality Asurance Policy, namely who applicable to.",
        "Must include a list of key Stakeholders, at least two categories, internal and external.",
        "Must include quality process definition and description of key terminology (levels of quality): Inspection, Quality Control, Quality Assurance and Quality Management.",
        "Must include Roles and Responsibilities of Quality Management Team including Course Director, Course Admin, Officer with Primary Responsibilities, Training Breanch Chief Head, etc. Also must include Quality Management Team composition and organization diagram/chart.",
        "Should include a RACI Matrix",
        "Must include a Quality Assurance Review Cycle and/or Continuous Improvement Process, After Action Review, Post-Course Review, Curriculum Review Board, Annual QA (System/Institution) Internal Review, Annual QA Report, External re-accreditation.",
        "Must include Key Performance Indicators (KPIs) and how used to measure progress. Also how the KPIs are measured - threshholds for low values.",
        "Must include surveys for students and graduate feedback, for supervisors, instructors and also staff satisfaction.",
        "Must include instructor monitoring and assessment as well as course monitoring.",
        "Must include a description of procedures (SOP/SOI) review cycle",
        "Must include a description of the QA Policy review or update cycle",
        "Must include a student Assessment strategy, namely formative and/or summative including assessment criteria, e.g. student engagement criteria – outstanding to unsatisfactory.",
        "Must include graduation criteria (attendance + grades) – and if they have diffrent rules for course graduation certificates - Attendace and/or Graduation Certificates",
        "Must include a description of the student appeals process",
        "Must include details about staff Management and recruitment, namely staff and/or instructor development (e.g. induction and/or orientation, initial training, continuous/further training, professional development, development plans).",
        "Must include descriptions about instructor monitoring, instructor assessment and/or evaluation, and course monitoring",
        "Must include description about definition and delivery of instruction, namely NATO Systems Approach to Training (SAT) (e.g., the five SAT Phases briefly describe Purpose/Process/Product) and also about Global Programming.",
        "Must include description about the conduct of Courses, namely scheduling and/or programming, resourcing, responsibilities, activity timelines for course delivery, etc.",
        "Must include a communication plan",
        "Must include a bried description about information systems and knowledge management.",
        "Must include a description about the public information process, namely briefly what and how (e.g., websites, social media, publications, etc.).",
        "Must include a relationship between teaching, research, doctrine development and Lessons Learned.",
        "Must include a Glossary or a list of definitions.",
        "Should include a list of SOPs."
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

    // Return the results as JSON
    echo json_encode(['results' => $results]);
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
        "model" => "gpt-4-turbo",
        "messages" => [
            ["role" => "system", "content" => "You are a helpful assistant."],
            ["role" => "user", "content" => "Analyze the following text to determine if the criterion is met:\n\nCriterion: $criterion\n\nDocument Text: $text\n\nAnswer with one of the following: Met, Partially Met, Not Met."]
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

    if (curl_errno($ch)) {
        error_log('cURL error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    error_log('OpenAI HTTP Code: ' . $http_code);
    error_log('OpenAI Response: ' . $response);

    if ($http_code !== 200) {
        error_log('OpenAI returned error code: ' . $http_code);
        return false;
    }

    $response_data = json_decode($response, true);

    if (isset($response_data['choices'][0]['message']['content'])) {
        return $response_data['choices'][0]['message']['content'];
    }

    error_log('Invalid response from OpenAI.');
    return false;
}