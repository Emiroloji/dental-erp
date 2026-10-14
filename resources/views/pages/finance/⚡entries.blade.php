<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component {};
?>

<div>
    <h1 class="text-[22px] font-medium tracking-tight text-ink mb-6">Finans</h1>
    <x-finance-tabs />
</div>
