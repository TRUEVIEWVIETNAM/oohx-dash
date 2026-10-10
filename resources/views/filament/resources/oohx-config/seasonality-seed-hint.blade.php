<div class="space-y-3 text-sm">
    <p>SSH vào Data Engine VPS rồi chạy:</p>

    <div class="bg-gray-900 text-gray-100 rounded-lg p-3 font-mono text-xs overflow-x-auto">
        <div>ssh -i /www/wwwroot/dash.oohx.net/storage/app/oohx-ssh/oohx_sync \</div>
        <div class="pl-4">oohx@139.162.20.95</div>
        <div class="mt-2">cd ~/apps/oohx-matrix/python-data-engine</div>
        <div>.venv/bin/python -m app.cli seed-seasonality</div>
    </div>

    <p class="text-gray-600 dark:text-gray-400">
        Seed sẽ insert defaults cho <strong>Hà Nội, HCMC, Đà Nẵng, Hải Phòng</strong>
        với pattern Tet low (tháng 1-2), summer peak (6-8), year-end rebound (12).
    </p>

    <p class="text-xs text-gray-500">
        Laravel UI không duplicate defaults bên Python để tránh drift khi Data Engine tune curves.
        Add city mới qua <strong>Thêm hệ số</strong> button hoặc sửa file bên Python.
    </p>
</div>
