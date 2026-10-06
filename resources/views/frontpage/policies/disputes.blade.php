@extends('frontpage.layouts.app')

@section('title', $page['title'] . ' – OOHX')

@section('content')
    <x-policy-shell :page="$page">
        @include('frontpage.policies.bodies.disputes')
    </x-policy-shell>
@endsection
