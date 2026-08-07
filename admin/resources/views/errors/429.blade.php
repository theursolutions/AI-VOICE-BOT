@extends('errors.layout')

@section('title', 'Too many requests')
@section('code', '429')
@section('heading', 'Slow down a moment')
@section('message', 'You’ve made a lot of requests in a short time. Please wait a few seconds and try again.')
