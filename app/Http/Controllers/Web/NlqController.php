<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\NlqIntentService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NlqController extends Controller
{
    public function __construct(
        protected NlqIntentService $nlqIntentService
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $query = trim((string) $request->input('query', ''));
        $resolution = null;

        if ($query !== '') {
            $resolution = $this->nlqIntentService->resolve($query);
        }

        return view('nlq.index', [
            'user' => $user,
            'query' => $query,
            'resolution' => $resolution,
        ]);
    }
}
