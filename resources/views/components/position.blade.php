<div>
<h3>{{ $company }}</h3>
<section class="mb-6">
    <div class="lg:inline-block lg:w-3/12 lg:align-top italic mb-2">{{ $when }}:</div>
    <div class="lg:inline-block lg:w-8/12 w-full">{{ $role }}</div>
</section>
<section class="mb-6">
    <div class="lg:inline-block lg:w-3/12 lg:align-top italic mb-2">
        Description:
    </div>
    <div class="position-description lg:inline-block lg:w-8/12 w-full">
        {{ $description }}
    </div>
</section>
<section class="mb-6">
    <div class="lg:inline-block lg:w-3/12 lg:align-top italic mb-2">
        Tasks:
    </div>
    <div class="lg:inline-block lg:w-8/12 w-full">
        @foreach(explode(',', $tasks) as $task)
            <span class="inline-block rounded-xl bg-purple-400 text-white px-3 mb-1">{{ $task }}</span>
        @endforeach
    </div>
</section>
</div>
