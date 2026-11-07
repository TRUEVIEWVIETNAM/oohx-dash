# Chốt duyệt tay cho deploy production

Dựng ngày 07/11/2026. Tệp này mô tả phần cấu hình **không nằm trong repo**: một
GitHub Environment tên `production` với người duyệt bắt buộc. Repo chỉ giữ một
dòng trỏ tới nó — `environment: production` trong job `deploy` của
`.github/workflows/deploy.yml`.

## Vì sao có

Đến 07/11/2026, merge một PR vào `main` là deploy production ngay, không có
bước nào ở giữa. Lượt deploy `42388153110` cùng ngày đi từ nút merge tới
`php artisan down` trên trang thật trong 4 phút 34, không hỏi gì.

Rủi ro không phải là một commit tồi — bộ test chặn việc đó. Rủi ro là **bấm
nhầm**: một `gh pr merge` gõ sai số, một nút merge trên giao diện GitHub bấm
vào PR đang mở sẵn trong tab khác. Trước chốt này, cái nhầm đó là một lượt
deploy. Sau chốt này, nó là một job đứng ở trạng thái "Waiting" trên tab
Actions, huỷ được bằng một nút.

## Nó KHÔNG bảo vệ cái gì

- **Không chống người cố ý.** Ai có quyền admin repo đều xoá được environment
  hoặc tự thêm mình vào danh sách duyệt. Đây là chốt chống nhầm.
- **Không chặn `workflow_dispatch`.** Chạy tay cũng đi qua job `deploy`, nên
  cũng phải đợi duyệt. Đó là chủ ý, nhưng cần biết: sau khi xoay khoá SSH,
  phép thử đường deploy cũng phải bấm duyệt một lần.
- **Không áp cho `dev`.** `deploy-dev.yml` không khai `environment:`, nên merge
  vào `dev` vẫn deploy `dev.oohx.net` tự động, không hỏi. Đó là chỗ để thử.
- **Không chặn ai sửa `.env` hoặc chạy lệnh trực tiếp trên VPS.** Chốt này
  nằm trên đường GitHub Actions, không nằm trên đường SSH.

## Cấu hình đang chạy

| Thứ | Giá trị | Vì sao |
|---|---|---|
| Tên environment | `production` | khớp đúng chuỗi trong `deploy.yml`; GitHub nối bằng tên, không bằng id |
| Người duyệt bắt buộc | `tuanna0703` | người duy nhất có quyền trên repo |
| `prevent_self_review` | `false` | xem mục dưới |
| `wait_timer` | không đặt | không cần độ trễ, cần một người đọc |
| Chính sách nhánh | chỉ `main` | lớp chặn thứ hai, xem mục dưới |
| Environment secrets | không có | bốn secret `VPS_*` và hai khoá MapTiler vẫn ở mức repo, dùng được như trước |

### `prevent_self_review` để `false` có chủ ý

Bật nó lên thì người kích lượt chạy không tự duyệt được. Dự án hiện có **đúng
một người** có quyền, nên bật nó là khoá cứng đường deploy production: không ai
khác bấm được, và chính người merge thì bị từ chối.

Khi có người thứ hai có quyền admin, đổi thành `true` là một dòng:

```bash
gh api --method PUT repos/TRUEVIEWVIETNAM/oohx-dash/environments/production \
    -F prevent_self_review=true -F wait_timer=0 \
    -f 'reviewers[][type]=User' -F 'reviewers[][id]=199213' \
    -f 'reviewers[][type]=User' -F 'reviewers[][id]=<id người thứ hai>'
```

### Chính sách nhánh là lớp chặn độc lập với trigger

`deploy.yml` nghe `push: [main]`. Nếu ai thêm một nhánh nữa vào đó, trigger sẽ
kích — nhưng job `deploy` vẫn bị GitHub từ chối ở lớp environment, vì chính
sách nhánh chỉ cho `main`. Hai lớp này không phụ thuộc nhau, và ca thử
`workflow production KHÔNG kích theo push vào dev` canh lớp thứ nhất.

## Dựng lại từ đầu

Cần quyền admin repo. Repo là PUBLIC nên protection rules dùng được không mất
phí; nếu sau này chuyển sang PRIVATE thì cần gói Pro/Team/Enterprise, và
**chuyển sang private sẽ làm chốt này mất tác dụng trong im lặng** — job
`deploy` chạy luôn, không đợi ai.

```bash
# 1. Tạo environment với người duyệt bắt buộc.
#    `id` là id số của tài khoản GitHub: gh api users/tuanna0703 --jq .id
cat > /tmp/env-production.json <<'JSON'
{
  "wait_timer": 0,
  "prevent_self_review": false,
  "reviewers": [ { "type": "User", "id": 199213 } ],
  "deployment_branch_policy": {
    "protected_branches": false,
    "custom_branch_policies": true
  }
}
JSON
gh api --method PUT \
    repos/TRUEVIEWVIETNAM/oohx-dash/environments/production \
    --input /tmp/env-production.json

# 2. Chỉ nhánh `main` được deploy vào environment này.
gh api --method POST \
    repos/TRUEVIEWVIETNAM/oohx-dash/environments/production/deployment-branch-policies \
    -f name='main' -f type='branch'
```

Phép kiểm sau khi dựng — phải thấy `required_reviewers` **và** `branch_policy`:

```bash
gh api repos/TRUEVIEWVIETNAM/oohx-dash/environments/production \
    --jq '[.protection_rules[].type]'
# ["required_reviewers","branch_policy"]

gh api repos/TRUEVIEWVIETNAM/oohx-dash/environments/production/deployment-branch-policies \
    --jq '[.branch_policies[].name]'
# ["main"]
```

Trong Git Bash phải **bỏ dấu `/` đầu** của đường dẫn API (`repos/...`, không
`/repos/...`): MSYS đổi `/repos/...` thành `C:/Program Files/Git/repos/...` và
`gh` báo `invalid API endpoint`.

## Quy trình deploy production sau khi có chốt

```
PR vào main → merge
   ↓
tests (4 job)  chạy ngay, không đợi ai
   ↓
deploy         đứng ở "Waiting" trên tab Actions
   ↓
người duyệt bấm "Review deployments" → "Approve and deploy"
   ↓
SSH vào VPS, 11 bước của deploy.sh
```

Lượt chạy đứng đợi hiện trên https://github.com/TRUEVIEWVIETNAM/oohx-dash/actions
kèm nút duyệt, và GitHub gửi thông báo cho người duyệt. Để huỷ thay vì duyệt:
"Reject", hoặc huỷ cả lượt chạy.

**Thời gian đợi có hạn.** Một job đợi duyệt bị huỷ sau **30 ngày** nếu không ai
bấm. Không phải vấn đề khi deploy hàng tuần, nhưng đáng biết nếu để treo.

## Lượt deploy đầu tiên qua chốt

Chốt có hiệu lực **ngay ở lượt merge đưa nó lên `main`**, không phải từ lượt
sau. GitHub đọc tệp workflow từ đúng SHA được push, và SHA đó đã chứa dòng
`environment: production` — nên lượt deploy ấy tự dừng lại đợi duyệt.

Đây là chỗ nó khác `deploy.sh`. `deploy.sh` chạy **trên VPS**: bash mở tệp,
rồi bước `[3] git reset --hard` thay tệp ấy giữa chừng, nhưng git ghi bản mới
rồi đổi tên nên bash vẫn đọc trọn inode cũ — bản mới chỉ có hiệu lực từ lượt
deploy sau. Workflow YAML không qua đường đó: GitHub đọc nó trước khi có máy
chủ nào tham gia, từ nội dung của chính commit vừa push. Hai tệp cùng nằm
trong một lượt deploy mà hiệu lực lệch nhau một lượt, và lý do nằm ở chỗ ai
đọc tệp.
