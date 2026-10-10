{{--
    Allowed content picker — Hivestack card style with icons
    Sets: spec.allow_image, spec.allow_video, spec.allow_html, spec.allow_zip
--}}
<div
    x-data="{
        items: [
            { key: 'allow_image', label: 'Images',      field: 'data.spec.allow_image', active: true  },
            { key: 'allow_video', label: 'Videos',      field: 'data.spec.allow_video', active: true  },
            { key: 'allow_html',  label: 'HTML',        field: 'data.spec.allow_html',  active: false },
            { key: 'allow_zip',   label: 'Zip archive', field: 'data.spec.allow_zip',   active: false },
        ],
        init() {
            const spec = $wire.data?.spec ?? {};
            this.items.find(i => i.key === 'allow_image').active = spec.allow_image !== false && spec.allow_image !== 0;
            this.items.find(i => i.key === 'allow_video').active = spec.allow_video !== false && spec.allow_video !== 0;
            this.items.find(i => i.key === 'allow_html').active  = !!spec.allow_html;
            this.items.find(i => i.key === 'allow_zip').active   = !!spec.allow_zip;
        },
        toggle(item) {
            item.active = !item.active;
            $wire.set(item.field, item.active);
        }
    }"
    x-init="init()"
    class="mt-2"
>
    <p class="mb-2 text-sm font-medium text-gray-700">Nội dung được phép <span class="text-red-500">*</span></p>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <template x-for="item in items" :key="item.key">
            <div
                @click="toggle(item)"
                :class="item.active
                    ? 'border-primary-400 bg-primary-50 ring-1 ring-primary-400'
                    : 'border-gray-200 bg-white hover:border-gray-300'"
                class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 p-5 transition-all duration-150 select-none"
            >
                <template x-if="item.key === 'allow_image'">
                    <svg class="h-10 w-10 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </template>
                <template x-if="item.key === 'allow_video'">
                    <svg class="h-10 w-10 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/>
                    </svg>
                </template>
                <template x-if="item.key === 'allow_html'">
                    <svg class="h-10 w-10 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                    </svg>
                </template>
                <template x-if="item.key === 'allow_zip'">
                    <svg class="h-10 w-10 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                    </svg>
                </template>
                <span class="text-sm font-medium text-gray-700" x-text="item.label"></span>
            </div>
        </template>
    </div>
</div>
