@extends('frontpage.layouts.app')

@section('title', $page['title'] . ' – OOHX')

@section('content')
    <x-policy-shell :page="$page">
        @include('frontpage.policies.bodies.fees')
    </x-policy-shell>
@endsection
