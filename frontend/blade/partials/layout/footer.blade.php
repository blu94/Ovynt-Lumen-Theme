<footer class="lumen-footer">
    <div class="lumen-shell">
        <p>&copy; {{ date('Y') }} {{ $settings['site_title'] ?? 'Lumen' }}</p>
        <x-plugin-slot name="account" :data="['screen' => 'footer']" />
    </div>
</footer>
