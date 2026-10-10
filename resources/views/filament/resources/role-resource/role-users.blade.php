@php
    $role = $getRecord();
    $users = $role->users()->with('ownerUsers.owner')->limit(50)->get();
@endphp

@if($users->isEmpty())
    <div class="text-sm text-gray-500 dark:text-gray-400 italic">
        Chưa có user nào với role này.
    </div>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="text-left py-2 pr-4 font-medium text-gray-600 dark:text-gray-400">Tên</th>
                    <th class="text-left py-2 pr-4 font-medium text-gray-600 dark:text-gray-400">Email</th>
                    <th class="text-left py-2 pr-4 font-medium text-gray-600 dark:text-gray-400">Media owner</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="py-1.5 pr-4 text-gray-700 dark:text-gray-300">{{ $user->name }}</td>
                        <td class="py-1.5 pr-4 text-gray-500 dark:text-gray-400">{{ $user->email }}</td>
                        <td class="py-1.5 pr-4">
                            @forelse($user->ownerUsers as $ou)
                                <span class="inline-flex items-center px-2 py-0.5 mr-1 mb-0.5 rounded-md text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                                    {{ $ou->owner?->name ?? '?' }}
                                    <span class="ml-1 text-gray-400">({{ \App\Models\OwnerUser::ROLE_LABELS[$ou->role] ?? $ou->role }})</span>
                                </span>
                            @empty
                                <span class="text-gray-400 text-xs">—</span>
                            @endforelse
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($role->users()->count() > 50)
        <div class="mt-2 text-xs text-gray-400">
            Hiển thị 50 / {{ $role->users()->count() }} users. Xem đầy đủ tại trang Users.
        </div>
    @endif
@endif
