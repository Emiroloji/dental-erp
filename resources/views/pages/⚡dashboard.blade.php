<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function logout(): void
    {
        Auth::logout();

        session()->invalidate();
        session()->regenerateToken();

        $this->redirect(route('login'));
    }
};
?>

<div class="min-h-screen bg-gray-100">
    <nav class="bg-white shadow px-6 py-4 flex items-center justify-between">
        <span class="font-semibold text-gray-800">Dental ERP</span>
        <div class="flex items-center gap-4">
            <span class="text-sm text-gray-600">{{ auth()->user()->name }} ({{ auth()->user()->role }})</span>
            <button wire:click="logout" class="text-sm text-red-600">Çıkış Yap</button>
        </div>
    </nav>

    <main class="p-6">
        <h1 class="text-2xl font-semibold text-gray-800">Kontrol Paneli</h1>
    </main>
</div>
