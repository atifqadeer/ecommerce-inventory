<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Manage inventory, orders, and profitability across every sales channel.">
        <meta name="color-scheme" content="light dark">
        <title>{{ config('app.name') }} — Commerce operations dashboard</title>
        <link rel="icon" href="/icon.svg" type="image/svg+xml">
        <link rel="icon" href="/icon-light-32x32.png" media="(prefers-color-scheme: light)">
        <link rel="icon" href="/icon-dark-32x32.png" media="(prefers-color-scheme: dark)">
        <link rel="apple-touch-icon" href="/apple-icon.png">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased">
        <div id="app"></div>
    </body>
</html>
