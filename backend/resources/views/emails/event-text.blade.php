{{-- Plain-text alternative of emails.event --}}
{!! $brand !!}

{!! $subject !!}

@foreach ($paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
@foreach ($blocks as $block)
@switch($block['type'])
@case('code')
{!! $block['value'] !!}

@break
@case('table')
{!! implode(' | ', $block['columns']) !!}
@foreach ($block['rows'] as $row)
{!! implode(' | ', $row) !!}
@endforeach

@break
@case('totals')
@foreach ($block['rows'] as $row)
{!! $row[0] !!}: {!! $row[1] !!}
@endforeach

@break
@case('facts')
@if ($block['title']){!! $block['title'] !!}
@endif
@foreach ($block['rows'] as $row)
{!! $row[0] !!}: {!! $row[1] !!}
@endforeach

@break
@case('note')
{!! $block['text'] !!}

@break
@endswitch
@endforeach
@if ($button)
{!! $button['label'] !!}: {!! $button['url'] !!}

@endif
--
{!! __('Help centre') !!}: {!! $footer['help'] !!}
@if ($footer['preferences']){!! __('Email preferences') !!}: {!! $footer['preferences'] !!}
@endif
@if ($footer['unsubscribe']){!! __('Unsubscribe') !!}: {!! $footer['unsubscribe'] !!}
@endif
{!! $footer['reason'] !!}
