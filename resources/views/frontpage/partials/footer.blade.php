<footer class="footer">
    <div class="w">
        <div class="ft-g">
            <div class="ft-br">
                <div class="ft-logo"><img id="logo-ft" src="" alt="OOHX"></div>
                <p class="ft-desc">OOH/DOOH Intelligence Marketplace. Kết nối agency, brand và media owner tại Việt Nam.</p>
                <div class="ft-socs">
                    <div class="ft-soc">fb</div>
                    <div class="ft-soc">in</div>
                    <div class="ft-soc">tw</div>
                    <div class="ft-soc">yt</div>
                </div>
                @include('frontpage.partials.company-legal')
            </div>
            <div class="ft-col">
                <h4>Khám phá</h4>
                <ul class="ft-ls">
                    <li><a href="{{ url('/explore') }}">Toàn bộ màn hình</a></li>
                    <li><a href="{{ url('/explore') }}">Biển quảng cáo</a></li>
                    <li><a href="{{ url('/explore') }}">LED ngoài trời</a></li>
                    <li><a href="{{ url('/explore') }}">LCD trung tâm thương mại</a></li>
                </ul>
            </div>
            <div class="ft-col">
                <h4>Thị trường</h4>
                <ul class="ft-ls">
                    <li><a href="#">Hà Nội</a></li>
                    <li><a href="#">TP.HCM</a></li>
                    <li><a href="#">Đà Nẵng</a></li>
                    <li><a href="#">63 tỉnh thành</a></li>
                </ul>
            </div>
            <div class="ft-col">
                <h4>Đại lý</h4>
                <ul class="ft-ls">
                    <li><a href="{{ url('/agency') }}">Bảng điều khiển</a></li>
                    <li><a href="#">So sánh</a></li>
                    <li><a href="#">API</a></li>
                </ul>
            </div>
            <div class="ft-col">
                <h4>Chủ sở hữu</h4>
                <ul class="ft-ls">
                    <li><a href="{{ url('/owners') }}">Danh sách màn hình</a></li>
                    <li><a href="#">Bảng điều khiển</a></li>
                    <li><a href="#">Hỗ trợ</a></li>
                </ul>
            </div>
            <div class="ft-col">
                <h4>Chính sách</h4>
                <ul class="ft-ls">
                    <li><a href="{{ url('/quy-che-hoat-dong') }}">Quy chế hoạt động</a></li>
                    <li><a href="{{ url('/chinh-sach-bao-mat') }}">Chính sách bảo mật</a></li>
                    <li><a href="{{ url('/giai-quyet-tranh-chap') }}">Cơ chế giải quyết tranh chấp, khiếu nại, phản ánh</a></li>
                    <li><a href="{{ url('/bang-phi') }}">Bảng phí dịch vụ</a></li>
                    <li><a href="{{ url('/phan-anh-to-chuc-xa-hoi') }}">Tiếp nhận phản ánh của TCXH</a></li>
                    <li><a href="{{ url('/phan-anh-to-chuc-xa-hoi/danh-sach') }}">Danh sách phản ánh của TCXH</a></li>
                </ul>
            </div>
        </div>
        <div class="ft-btm">
            <div>Bản quyền &copy; {{ date('Y') }} OOHX. Bảo lưu mọi quyền.</div>
            <div class="ft-bls">
                <a href="{{ url('/quy-che-hoat-dong') }}">Quy chế hoạt động</a>
                <a href="{{ url('/chinh-sach-bao-mat') }}">Chính sách bảo mật</a>
                <a href="{{ url('/phan-anh-to-chuc-xa-hoi') }}">Liên hệ</a>
            </div>
        </div>
    </div>
</footer>
