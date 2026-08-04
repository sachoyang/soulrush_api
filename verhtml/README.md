# Soul Rush API — Node.js + HTML/CSS/JS 버전

원본 PHP 프로젝트(`../`)를 **PHP 0줄**로 포팅한 것.
원본은 그대로 살아 있으므로 언제든 되돌아갈 수 있다.

| | 원본 | 이 버전 |
|---|---|---|
| 서버 | Apache + PHP 5.6 (32비트) | Node.js |
| 게임 API | `login.php`, `get_abilities.php` … | **같은 경로 그대로** |
| 관리자 화면 | PHP가 HTML을 찍어냄 | 정적 HTML + CSS + JS |
| DB | MySQL `soulsusers` | **같은 DB 공유** |

---

## 왜 백엔드가 남아 있나

브라우저 JS는 MySQL에 직접 붙을 수 없다. DB 앞에는 반드시 서버가 필요하다.
바뀐 건 **그 서버의 언어가 PHP → JavaScript** 라는 점이다.

```
[Unity 게임]  ─┐
                ├─→  Node.js (server/)  ─→  MySQL
[관리자 브라우저] ─┘         ↑
   HTML+CSS+JS (public/) ────┘  같은 프로세스가 정적 파일도 서빙
```

---

## 실행

```bash
cd verhtml
npm install     # 최초 1회
npm start
```

```
관리자 대시보드 : http://localhost:3000/
게임 API        : http://localhost:3000/login.php  등
```

개발 중에는 파일 저장 시 자동 재시작되는 `npm run dev` 가 편하다.

### 설정

`server/config.js` 를 고치거나 환경변수로 덮어쓴다.

| 환경변수 | 기본값 | 설명 |
|---|---|---|
| `SR_PORT` | `3000` | 포트 |
| `SR_DB_HOST` | `localhost` | |
| `SR_DB_NAME` | `soulsusers` | |
| `SR_DB_USER` | `root` | |
| `SR_DB_PASS` | (원본 db_connect.php 와 동일) | |
| `SR_ADMIN_PW` | `admin1234` | 관리자 대시보드 비밀번호 |
| `SR_ADMIN_TTL` | 12시간 | 관리자 토큰 유효 시간(ms) |

```bash
set SR_PORT=8080 && npm start     # Windows
```

---

## 게임 클라이언트 연결

**서버 주소 한 줄만 바꾸면 된다.** 경로·파라미터·응답 JSON이 원본과 동일하다.

```csharp
// 예전:  http://192.168.0.10/soulrush_api/
// 지금:  http://192.168.0.10:3000/
```

전체 요청/응답 명세는 **[ENDPOINTS.md](ENDPOINTS.md)** 참고.

---

## 관리자 대시보드

`http://localhost:3000/` → 비밀번호 입력 (`admin1234`)

| 화면 | 대응 원본 |
|---|---|
| 👤 유저 DB 관리 | `log_viewer.php` |
| ⚔️ 스킬 모듈 관리 | `ability_manager.php` |
| 🏆 팀 랭킹 관리 | `ranking_viewer.php` |
| 💥 크래시 리포트 | `crash_viewer.php` |

프런트를 **다른 서버에 따로 올려도** 된다. `public/config.js` 의 한 줄을 고치거나,
화면 우측 상단 **[API 주소]** 버튼으로 바꾸면 된다(localStorage 에 저장됨).

```js
// public/config.js
window.SOULRUSH_API_BASE = 'http://192.168.0.10:3000';
```

---

## 폴더 구조

```
verhtml/
├── server/                  Node.js 백엔드
│   ├── index.js             진입점 — 라우트 조립, 정적 서빙
│   ├── config.js            설정 (DB, 포트, 관리자 비밀번호)
│   ├── db.js                MySQL 풀 — BIGINT를 문자열로 받는 설정이 핵심
│   ├── mask.js              64비트 비트마스크 (BigInt)      ← mask_helper.php
│   ├── auth.js              bcrypt($2y$) + 관리자 토큰
│   ├── logger.js            server_log.txt 기록            ← logger.php
│   ├── ability_write.js     7테이블 UPSERT 공용 로직        ← ability_write.php
│   └── routes/
│       ├── game.js          게임용 API (원본과 같은 경로)
│       └── admin.js         관리자 API
├── public/                  정적 프런트 (HTML + CSS + JS)
│   ├── config.js            ★ API 주소
│   ├── index.html           로그인 + 허브                  ← admin_hub.php
│   ├── users.html
│   ├── abilities.html
│   ├── rankings.html
│   ├── crashes.html
│   ├── css/style.css        5개 화면의 <style> 을 하나로
│   └── js/
│       ├── api.js           공용 클라이언트 (토큰/주소/유틸)
│       └── hub·users·abilities·rankings·crashes.js
├── ENDPOINTS.md             ★ 클라이언트용 엔드포인트 명세
└── README.md
```

---

## 포팅에서 신경 쓴 부분

### 1. 64비트 스킬 마스크

원본은 **32비트 PHP** 라 `(int)` 캐스팅하면 bit 31 이상이 깨졌고, 그래서 bcmath
10진 문자열로 우회했다. Node는 `BigInt` 가 있어 그 우회가 사라졌지만,
**"응답에는 숫자 문자열로 내린다"** 는 규약은 그대로 지켰다.
JSON 숫자로 내리면 파서 쪽에서 정밀도가 깨지기 때문이다.

- MySQL 드라이버를 `bigNumberStrings: true` 로 두어 BIGINT를 문자열로 받는다
- `9223372036854775807` 왕복 저장/조회 검증 완료

### 2. 비밀번호 해시 — 원본 PHP와 양방향 호환

PHP `password_hash()` 는 `$2y$` bcrypt 를 만든다. 그런데 **PHP 5.6의
crypt_blowfish 는 `$2b$` 를 모른다** — Node가 기본값대로 `$2b$` 로 저장하면
원본 `login.php` 가 그 유저를 영영 검증하지 못한다.

그래서 저장 시 접두사를 `$2y$` 로 통일하고, 검증 시에는 `$2a$` 로 정규화한다.
실제 PHP 5.6.31 바이너리로 양방향 확인했다:

- Node가 만든 해시 → PHP `password_verify()` ✅
- PHP가 만든 해시 → Node `verifyPassword()` ✅

즉 **기존 유저는 그대로 로그인되고**, Node에서 PW를 초기화한 유저도 원본 PHP에서 로그인된다.

### 3. FLOAT 정밀도

스킬 수치 컬럼은 전부 MySQL `FLOAT`(단정밀도)다.
PHP는 MySQL이 보낸 텍스트 `"2.3"` 을 그대로 받았지만, mysql2는 float32를 float64로
확장해 `2.299999952316284` 가 된다. float32 유효자릿수로 되돌려 **원본과 같은 `2.3`** 이
나가도록 보정했다(`ability_write.js` 의 `f32()`).

### 4. 요청 본문 3종

Unity는 보내는 방식에 따라 본문 형식이 다르다. PHP `$_POST` 가 알아서 처리해 주던
부분이라, `multipart/form-data` · `urlencoded` · `json` 을 모두 받아 `req.body` 로 통일했다.

### 5. PHP `empty()` 의 함정

PHP는 문자열 `"0"` 도 `empty()` 로 친다. 원본이 `empty()` 로 막던 자리를 JS `!v` 로
바꾸면 동작이 미묘하게 달라지므로 `phpEmpty()` 로 그대로 재현했다.
반대로 `update_skills` 의 `unlocked_skills` 는 원본도 `=== ''` 검사여서
`"0"`(스킬 없음)이 정상 통과한다 — 이 차이를 그대로 유지했다.

---

## 검증 현황

실제 DB에 붙여 전 엔드포인트를 확인했다.

| 항목 | 결과 |
|---|---|
| 회원가입 / 중복 거부 | ✅ |
| 로그인 (multipart · urlencoded) / 실패 처리 | ✅ |
| 세션 하트비트 valid·invalid | ✅ |
| admin 권한 검증 | ✅ |
| 64비트 마스크 `9223372036854775807` 왕복 | ✅ |
| 스킬 카탈로그 (FLOAT `2.3` 원본 일치) | ✅ |
| 랭킹 조회 / 등록 / 3인 아닌 기록 거부 | ✅ |
| 크래시 수집 · 중복 `duplicate` · type/날짜 검증 | ✅ |
| UTF-8 한글 왕복 | ✅ |
| bcrypt PHP 5.6 ↔ Node 양방향 | ✅ |
| 관리자 API 전체 (조회 + 쓰기) | ✅ |
| 토큰 없는 접근 401 | ✅ |
| 정적 페이지·에셋 서빙 | ✅ |

> 테스트로 만든 임시 데이터(`__porttest__` 계정 등)는 모두 삭제했다.
> **브라우저에서 각 관리자 화면을 눈으로 확인하는 것**은 남아 있다.

---

## 원본으로 되돌리려면

아무것도 지울 필요 없다. Node 서버를 끄고 클라이언트 주소를 원래대로
(`http://<host>/soulrush_api/`) 되돌리면 끝이다. 원본 PHP는 손대지 않았다.
