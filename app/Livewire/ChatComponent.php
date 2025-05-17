<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Http;
use Livewire\Attributes\On;
use Livewire\Component;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;

class ChatComponent extends Component
{
    public $todos = [];

    public $key = null;

    public $messages = [];

    public $isTyping = false;

    public $input = '';

    public $streamedResponse = '';

    public function mount()
    {
        $this->fetchTodos();
    }

    public function fetchTodos()
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . config('sanctum.token'),
        ])->get(config('app.url') . '/api/todos');

        $this->todos = $response->json('todos') ?? [];
    }

    public function toggleTodo($todoId)
    {
        $todoId = (int) $todoId;

        foreach ($this->todos as $key => $todo) {
            if ($todo['id'] === $todoId) {
                $this->todos[$key]['completed'] = !$todo['completed'];
                $this->key = $key;
                break;
            }
        }

        Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . config('sanctum.token'),
        ])->put(config('app.url') . '/api/todos/' . $todoId, [
            'completed' => $this->todos[$this->key]['completed'],
        ]);
    }

    public function send()
    {
        $prompt = $this->input;

        // Add user message to messages array immediately
        $this->messages[] = ['user' => 'You', 'text' => $prompt];
        $this->input = '';

        // Reset streamed response and set typing state
        $this->streamedResponse = '';
        $this->isTyping = true;

        // Dispatch UI updates first
        $this->dispatch('scroll-down');

        // Dispatch the event to trigger the AI response
        $this->dispatch('getAiResponse', $prompt);
    }

    #[On('getAiResponse')]
    public function getAIResponse($prompt)
    {
        $this->streamResponse($prompt);
    }

    public function streamResponse($prompt)
    {
        $todoTool = Tool::as('todos')
            ->for('Create new todos')
            ->withStringParameter('todo', 'The title for the todo')
            ->using(function (string $todo): string {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('sanctum.token'),
                    'Accept' => 'application/json'
                ])->post(config('app.url') . '/api/todos', [
                    'title' => $todo,
                ]);

                // Refresh todos after creating a new one
                $this->fetchTodos();

                return "The new todo '{$todo}' was created!";
            });

        $stream = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4')
            ->withMaxSteps(2)
            ->withPrompt($prompt)
            ->withTools([$todoTool])
            ->asStream();

        foreach ($stream as $chunk) {
            // Stream only new chunk text
            $this->stream('response', $chunk->text);

            // Accumulate full response for storage
            $this->streamedResponse .= $chunk->text;
        }

        // Add completed response to messages array
        $this->messages[] = ['user' => 'AI', 'text' => $this->streamedResponse];

        // Reset streaming state
        $this->streamedResponse = '';
        $this->isTyping = false;

        $this->dispatch('scroll-down');
    }

    public function render()
    {
        return view('livewire.chat-component');
    }
}
