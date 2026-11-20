<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\V1\NlqController as ApiNlqController;
use App\Http\Controllers\Controller;
use App\Services\NlqIntentService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NlqController extends Controller
{
    public function __construct(
        protected NlqIntentService $nlqIntentService,
        protected ApiNlqController $apiNlqController
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $query = trim((string) $request->input('query', ''));
        $answer = null;
        $error = null;

        if ($query !== '') {
            try {
                $response = $this->apiNlqController->ask($request);
                $data = $response->getData(true);
                if ($response->status() >= 400) {
                    $error = $data['message'] ?? 'Unable to process natural-language question.';
                } else {
                    $answer = $data;
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('nlq.index', [
            'user' => $user,
            'query' => $query,
            'answer' => $answer,
            'error' => $error,
        ]);
    }

    public function ask(Request $request): View
    {
        return $this->index($request);
    }
}
