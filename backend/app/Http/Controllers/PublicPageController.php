<?php

namespace App\Http\Controllers;

class PublicPageController extends Controller
{
    public function privacy()
    {
        return view('public.privacy', $this->shared());
    }

    public function support()
    {
        return view('public.support', $this->shared());
    }

    private function shared(): array
    {
        return [
            'appName' => config('shopify.app_name'),
            'supportEmail' => config('shopify.support_email'),
        ];
    }
}
