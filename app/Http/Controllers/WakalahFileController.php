<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Bimbsec\WakalahLetter;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class WakalahFileController extends Controller
{
    public function __construct(private WakalahLetter $wakalah) {}

    public function __invoke(Customer $customer): Response
    {
        Gate::authorize('view', $customer);
        $bytes = $this->wakalah->render($customer);
        $download = request()->boolean('download');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="Wakalah-'.$customer->reference.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
