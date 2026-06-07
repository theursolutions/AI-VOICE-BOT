<!-- start page title -->
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0 font-size-18">{{ $pagetitle }}</h4>

            <div class="page-title-right">
                <ol class="breadcrumb m-0">
                    @php
                    $total = count($breadcrumbs);
                    $active = $total-1;
                    @endphp
                    @foreach ($breadcrumbs as $key => $item)
                        @if ($key == $active)
                            <li class="breadcrumb-item active" aria-current="page">
                                <a href="{{ url($urls[$key]) }}">{{$item}}</a>
                            </li>
                        @else
                            <li class="breadcrumb-item">
                                <a href="{{ url($urls[$key]) }}">{{$item}}</a>
                            </li>
                        @endif
                    @endforeach
                </ol>
            </div>

        </div>
    </div>
</div>
<!-- end page title -->
