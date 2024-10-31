<?php
error_reporting(E_ERROR);
ini_set('display_errors', 0);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $db = new mysqli('localhost', 'root', '');
        
        if ($db->connect_error) {
            throw new Exception("Connection failed: " . $db->connect_error);
        }

        $db->select_db("reservesphp");
        

        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            throw new Exception("Invalid JSON data received");
        }
        
        if ($data['action'] === 'suggest') {
            $partial = $db->real_escape_string($data['partial']);
            $stmt = $db->prepare("SELECT word, frequency FROM word WHERE word LIKE CONCAT(?, '%') ORDER BY frequency DESC, word ASC LIMIT 10");
            $stmt->bind_param("s", $partial);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $suggestions = [];
            while ($row = $result->fetch_assoc()) {
                $suggestions[] = [
                    'word' => $row['word'],
                    'frequency' => intval($row['frequency'])
                ];
            }
            
            echo json_encode(['success' => true, 'suggestions' => $suggestions]);
            exit;
        }
        
        if ($data['action'] === 'addWord') {
            $word = $db->real_escape_string($data['word']);
            $stmt = $db->prepare("INSERT INTO word (word) VALUES (?) ON DUPLICATE KEY UPDATE frequency = frequency + 1");
            $stmt->bind_param("s", $word);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to add word");
            }
            
            echo json_encode(['success' => true]);
            exit;
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Word Suggester</title>
    <script src="https://unpkg.com/brain.js"></script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            background: #f0f2f5;
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #1a1a1a;
            margin-bottom: 1.5rem;
            font-size: 1.8rem;
        }

        .input-wrapper {
            position: relative;
            margin-bottom: 1rem;
        }

        input {
            width: 100%;
            padding: 12px 16px;
            font-size: 1rem;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            transition: all 0.2s ease;
            outline: none;
        }

        input:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25);
        }

        input:disabled {
            background: #f5f5f5;
            cursor: not-allowed;
        }

        .suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e1e1e1;
            border-radius: 8px;
            margin-top: 4px;
            max-height: 200px;
            overflow-y: auto;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: none;
        }

        .suggestion-item {
            padding: 10px 16px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f0f0f0;
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-item:hover {
            background: #f8f9fa;
        }

        .confidence {
            color: #6c757d;
            font-size: 0.875rem;
        }

        .status {
            margin-top: 1rem;
            padding: 1rem;
            border-radius: 8px;
            background: #e9ecef;
            font-size: 0.875rem;
            color: #495057;
        }

        .error {
            background: #fee;
            color: #c00;
            display: none;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
        }

        .loader {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            display: none;
        }

        @keyframes spin {
            0% { transform: translateY(-50%) rotate(0deg); }
            100% { transform: translateY(-50%) rotate(360deg); }
        }

        .info {
            margin-top: 2rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 0.875rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Smart Word Suggester</h1>
        <div class="input-wrapper">
            <input 
                type="text" 
                id="wordInput" 
                placeholder="Start typing to see suggestions..." 
                disabled
                autocomplete="off"
            >
            <div class="loader" id="loader"></div>
            <div class="suggestions" id="suggestions"></div>
        </div>
        <div class="status" id="status">Initializing system...</div>
        <div class="error" id="error"></div>
        <div class="info">
            Type at least 2 characters to see suggestions. Click a suggestion to select it.
        </div>
    </div>

    <script>
        class WordSuggester {
            constructor() {
                this.net = new brain.recurrent.LSTM({
                    hiddenLayers: [8],
                    maxPredictionLength: 20
                });
                this.trainedWords = new Set();
                this.isNetworkReady = false;
                this.inputElement = document.getElementById('wordInput');
                this.suggestionsElement = document.getElementById('suggestions');
                this.loaderElement = document.getElementById('loader');
                this.statusElement = document.getElementById('status');
                this.errorElement = document.getElementById('error');
                this.debounceTimer = null;

                this.initialize();
            }

            async initialize() {
                try {
                    await this.trainInitialNetwork();
                    this.setupEventListeners();
                    this.inputElement.disabled = false;
                    this.updateStatus('Ready! Start typing to see suggestions.');
                } catch (error) {
                    this.showError('Failed to initialize: ' + error.message);
                }
            }

            async trainInitialNetwork() {
                const initialWords = [
                    'the', 'be', 'to', 'of', 'and',
                    'in', 'that', 'have', 'it', 'for'
                ];

                this.updateStatus('Training network...');
                
                try {
                    await this.trainNetwork(initialWords.map(word => ({
                        input: word,
                        output: word
                    })));
                    this.isNetworkReady = true;
                } catch (error) {
                    throw new Error('Network training failed: ' + error.message);
                }
            }

            async trainNetwork(data) {
                for (let i = 0; i < data.length; i++) {
                    const item = data[i];
                    this.updateStatus(`Training: ${Math.round((i / data.length) * 100)}%`);
                    
                    await new Promise(resolve => setTimeout(resolve, 0));
                    
                    try {
                        this.net.train([item], {
                            iterations: 10,
                            errorThresh: 0.011,
                            log: false
                        });
                        this.trainedWords.add(item.input);
                    } catch (error) {
                        console.error('Training error for word:', item.input, error);
                    }
                }
            }

            setupEventListeners() {
                this.inputElement.addEventListener('input', this.handleInput.bind(this));
                this.inputElement.addEventListener('keydown', this.handleKeydown.bind(this));
                document.addEventListener('click', this.handleClickOutside.bind(this));
            }

            async handleInput(event) {
                clearTimeout(this.debounceTimer);
                const input = event.target.value.trim().toLowerCase();

                if (input.length < 2) {
                    this.hideSuggestions();
                    return;
                }

                this.debounceTimer = setTimeout(async () => {
                    this.showLoader();
                    try {
                        const suggestions = await this.getSuggestions(input);
                        this.showSuggestions(suggestions, input);
                    } catch (error) {
                        this.showError(error.message);
                    }
                    this.hideLoader();
                }, 300);
            }

            async getSuggestions(input) {
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'suggest',
                            partial: input
                        })
                    });

                    if (!response.ok) throw new Error('Network response was not ok');
                    
                    const data = await response.json();
                    
                    if (!data.success) throw new Error(data.error);

                    let suggestions = data.suggestions;

                    if (this.isNetworkReady) {
                        try {
                            const prediction = this.net.run(input);
                            if (prediction && !suggestions.some(s => s.word === prediction)) {
                                suggestions.push({
                                    word: prediction,
                                    frequency: 0
                                });
                            }
                        } catch (error) {
                            console.error('Prediction error:', error);
                        }
                    }

                    return suggestions;

                } catch (error) {
                    throw new Error('Failed to fetch suggestions: ' + error.message);
                }
            }

            showSuggestions(suggestions, input) {
                this.suggestionsElement.innerHTML = '';
                
                if (suggestions.length === 0) {
                    this.hideSuggestions();
                    return;
                }

                suggestions.forEach(suggestion => {
                    const div = document.createElement('div');
                    div.className = 'suggestion-item';
                    
                    const confidence = this.calculateConfidence(input, suggestion.word);
                    
                    div.innerHTML = `
                        <span>${suggestion.word}</span>
                        <span class="confidence">${(confidence * 100).toFixed(0)}%</span>
                    `;
                    
                    div.addEventListener('click', () => this.selectSuggestion(suggestion.word));
                    
                    this.suggestionsElement.appendChild(div);
                });

                this.suggestionsElement.style.display = 'block';
            }

            calculateConfidence(input, suggestion) {
                let matchingChars = 0;
                const maxLength = Math.max(input.length, suggestion.length);
                
                for (let i = 0; i < input.length; i++) {
                    if (input[i] === suggestion[i]) {
                        matchingChars++;
                    }
                }
                
                return matchingChars / maxLength;
            }

            async selectSuggestion(word) {
                this.inputElement.value = word;
                this.hideSuggestions();
                
                try {
                    await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'addWord',
                            word: word
                        })
                    });
                    
                    if (!this.trainedWords.has(word)) {
                        await this.trainNetwork([{
                            input: word,
                            output: word
                        }]);
                    }
                } catch (error) {
                    this.showError('Failed to save word: ' + error.message);
                }
            }

            handleKeydown(event) {
                if (event.key === 'Escape') {
                    this.hideSuggestions();
                }
            }

            handleClickOutside(event) {
                if (!this.inputElement.contains(event.target) && 
                    !this.suggestionsElement.contains(event.target)) {
                    this.hideSuggestions();
                }
            }

            showLoader() {
                this.loaderElement.style.display = 'block';
            }

            hideLoader() {
                this.loaderElement.style.display = 'none';
            }

            hideSuggestions() {
                this.suggestionsElement.style.display = 'none';
            }

            updateStatus(message) {
                this.statusElement.textContent = message;
            }

            showError(message) {
                this.errorElement.textContent = message;
                this.errorElement.style.display = 'block';
                setTimeout(() => {
                    this.errorElement.style.display = 'none';
                }, 5000);
            }
        }

        // Initialize the system when the page loads
        document.addEventListener('DOMContentLoaded', () => {
            new WordSuggester();
        });
    </script>
</body>