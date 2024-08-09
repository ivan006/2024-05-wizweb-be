<?php

namespace QuicklistsOrmApi\Console\Commands;

use Illuminate\Support\Facades\Storage;

class WordSplitter {
    private $commonWords;

    public function __construct($filename = null) {
        $url = 'https://raw.githubusercontent.com/first20hours/google-10000-english/master/20k.txt';
        $savePath = storage_path('app/common-words.txt');
        $sortedPath = storage_path('app/sorted-common-words.txt');

        if ($filename) {
            $savePath = $filename;
        }

        if (!file_exists($savePath)) {
            $this->downloadWordsList($url, $savePath);
        }

        if (!file_exists($sortedPath)) {
            $words = $this->sortAndFilterWords($savePath);
            $this->saveSortedWords($words, $sortedPath);
        }

        $this->commonWords = $this->loadCommonWords($sortedPath);
    }

    private function downloadWordsList($url, $savePath) {
        $content = file_get_contents($url);
        if ($content === false) {
            throw new \Exception("Failed to download words list from $url");
        }
        file_put_contents($savePath, $content);
    }

    private function sortAndFilterWords($filePath) {
        $words = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $commonTwoLetterWords = ["am", "an", "as", "at", "be", "by", "do", "go", "he", "if", "in", "is", "it", "me", "my", "no", "of", "on", "or", "so", "to", "up", "us", "we"];
        $filteredWords = array_filter($words, function($word) use ($commonTwoLetterWords) {
            return strlen($word) >= 3 || in_array($word, $commonTwoLetterWords);
        });
        usort($filteredWords, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        return $filteredWords;
    }

    private function saveSortedWords($words, $sortedFilePath) {
        file_put_contents($sortedFilePath, implode("\n", $words));
    }

    private function loadCommonWords($filename) {
        $words = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_flip($words); // Use array_flip to quickly check if a word exists
    }

    public function split($input) {
        // Remove non-alphabetic characters
        $input = preg_replace('/[^a-zA-Z]/', '', $input);
        $input = strtolower($input);
        $result = $this->splitRecursive($input);
        return ['words' => $result, 'log' => $this->createLog($result)];
    }

    private function splitRecursive($input) {
        $length = strlen($input);
        if ($length === 0) {
            return [];
        }

        foreach ($this->commonWords as $word => $value) {
            $wordLength = strlen($word);
            if (substr($input, 0, $wordLength) === $word) {
                $remaining = substr($input, $wordLength);
                return array_merge([$word], $this->splitRecursive($remaining));
            }
        }

        // If no words matched, return the input as a single segment
        return [$input];
    }

    private function createLog($words) {
        $log = [];
        $position = 0;
        foreach ($words as $word) {
            $log[] = ['segment' => $word, 'position' => $position];
            $position += strlen($word);
        }
        return $log;
    }
}
