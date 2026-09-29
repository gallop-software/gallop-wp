#!/usr/bin/env node
// Checks for the API key and the comments routes, run against the local WordPress
// that tests/playground/run.sh starts.
//
//   node tests/playground/checks.mjs                    the main run
//   MODE=constant KEY=gallopwp_... node ...checks.mjs   server started with --define GALLOP_WP_API_KEY <KEY>
//   MODE=constant-invalid node ...checks.mjs            server started with --define GALLOP_WP_API_KEY short
//   MODE=not-local node ...checks.mjs                   server started with BLUEPRINT=.../blueprint-production.json
//
// BASE sets the address, default http://127.0.0.1:9400. The main run ends by
// uninstalling the plugin's data, so restart the server before running it again.

const BASE = (process.env.BASE || 'http://127.0.0.1:9400').replace(/\/$/, '')
const MODE = process.env.MODE || 'main'
const API = `${BASE}/wp-json`
const COMMENTS = `${API}/gallop/v1/comments`
const TEST = `${API}/gallop-test/v1`

let passed = 0
const failures = []

function check(name, condition, detail) {
  if (condition) {
    passed++
    console.log(`  ok    ${name}`)
  } else {
    failures.push(name)
    console.log(`  FAIL  ${name}${detail === undefined ? '' : `\n        ${JSON.stringify(detail)}`}`)
  }
}

async function call(method, url, { body, headers = {} } = {}) {
  const res = await fetch(url, {
    method,
    headers: { ...(body === undefined ? {} : { 'Content-Type': 'application/json' }), ...headers },
    body: body === undefined ? undefined : JSON.stringify(body),
    redirect: 'manual',
  })
  const text = await res.text()
  let json = null
  try {
    json = JSON.parse(text)
  } catch {
    // Not JSON: left as text.
  }
  return { status: res.status, json, text, headers: res.headers }
}

const get = (url, options) => call('GET', url, options)
const post = (url, body, headers) => call('POST', url, { body, headers })
const option = (name, value) => post(`${TEST}/option`, { name, value })
const raw = async (id) => (await get(`${TEST}/comment/${id}`)).json
const state = async () => (await get(`${TEST}/state`)).json
const mail = async () => (await get(`${TEST}/mail`)).json
const clearMail = () => call('DELETE', `${TEST}/mail`)

// WordPress refuses a second comment from the same address or email within
// fifteen seconds, so every comment that is meant to be accepted gets its own.
let visitor = 0
function comment(fields = {}) {
  visitor++
  return {
    parent: 0,
    authorName: `Visitor ${visitor}`,
    authorEmail: `visitor${visitor}@example.com`,
    authorUrl: '',
    content: `Comment number ${visitor}, written at ${Date.now()}.`,
    ip: `203.0.113.${visitor}`,
    userAgent: 'Mozilla/5.0 (Test) Gallop/1.1',
    referer: 'https://front-end.example/2026/09/open-post/',
    ...fields,
  }
}

const wrongKey = (n = 0) => `gallopwp_${'wrongKEY0123456789abcdefghijklmnopqrstuv'.slice(0, 40)}${String(n).padStart(3, '0')}`
const keyed = (key) => ({ 'X-Gallop-WP-Key': key })

async function main() {
  console.log(`\nGallop checks against ${BASE}\n`)

  const seed = (await post(`${TEST}/seed`)).json
  const { posts, comments } = seed
  await post(`${TEST}/reset-limits`)
  await clearMail()

  console.log('Reading')
  {
    const res = await get(`${COMMENTS}?post=${posts.open}`)
    const data = res.json
    check('a post with comments answers 200', res.status === 200, res.status)
    check('only approved comments, no pingback', data.comments.length === 5 && data.count === 5, data.comments.map((c) => c.id))
    check('oldest first', data.comments.map((c) => c.id).join() === [comments.first, comments.reply, comments.second, comments.nested, comments.third].join(), data.comments.map((c) => c.id))
    check('replies carry their parent', data.comments[1].parent === comments.first && data.comments[3].parent === comments.reply)
    check('the post author is marked', data.comments[1].isPostAuthor === true && data.comments[0].isPostAuthor === false)
    check('open, thread depth and name rule are reported', data.open === true && data.threadDepth === 5 && data.requireNameEmail === true && data.truncated === false, data)
    check('no email address or IP anywhere in the response', !/reader@example\.com|198\.51\.100\.20|admin@/.test(res.text))
    check('each comment has exactly the documented fields', Object.keys(data.comments[0]).sort().join() === 'authorName,authorUrl,avatar,content,dateGmt,id,isPostAuthor,parent', Object.keys(data.comments[0]))
    check('caches are asked to check back', /no-cache/.test(res.headers.get('cache-control') || ''), res.headers.get('cache-control'))

    const busted = await get(`${COMMENTS}?post=${posts.open}&_=${Date.now()}`)
    check('an unknown query parameter is tolerated', busted.status === 200 && busted.json.comments.length === 5)

    const hidden = []
    for (const id of [posts.draft, posts.private, posts.protected, 99999999]) {
      const r = await get(`${COMMENTS}?post=${id}`)
      hidden.push(`${r.status} ${r.json?.code} ${r.json?.message}`)
    }
    check('draft, private, protected and missing posts give the same 404', new Set(hidden).size === 1 && hidden[0].startsWith('404 gallop_comments_post_not_found'), hidden)

    check('a post id that is not a number is refused', (await get(`${COMMENTS}?post=abc`)).status === 400)
    check('a missing post id is refused', (await get(COMMENTS)).status === 400)

    const closed = await get(`${COMMENTS}?post=${posts.closed}`)
    check('a closed post reports open: false', closed.status === 200 && closed.json.open === false, closed.json)

    await option('thread_comments', '0')
    check('threading off reports a depth of 1', (await get(`${COMMENTS}?post=${posts.open}`)).json.threadDepth === 1)
    await option('thread_comments', '1')

    await option('show_avatars', '0')
    check('avatars off reports avatar: null', (await get(`${COMMENTS}?post=${posts.open}`)).json.comments[0].avatar === null)
    await option('show_avatars', '1')

    await post(`${TEST}/option`, { name: 'comment_registration', value: '1' })
    check('a site that requires login to comment reports open: false', (await get(`${COMMENTS}?post=${posts.open}`)).json.open === false)
    await option('comment_registration', '0')
  }

  console.log('\nThe key')
  let key
  {
    const before = await state()
    check('nothing is stored before a key is generated', !('gallop_api_key_hash' in before), Object.keys(before))

    const noKey = await post(COMMENTS, { post: posts.open, ...comment() })
    check('no key: 401 gallop_key_missing', noKey.status === 401 && noKey.json.code === 'gallop_key_missing', noKey.json)

    const wellFormed = await post(COMMENTS, { post: posts.open, ...comment() }, keyed(wrongKey()))
    check('with no key configured, a well-formed key matches nothing', wellFormed.status === 401 && wellFormed.json.code === 'gallop_key_invalid', wellFormed.json)

    const empty = await post(COMMENTS, { post: posts.open, ...comment() }, keyed(''))
    check('an empty key never matches', empty.status === 401, empty.json)

    let fails = Object.entries(await state()).filter(([name]) => name.startsWith('_transient_gallop_key_fail_'))
    check('a wrong key is counted once, a missing key not at all', fails.length === 1 && Number(fails[0][1]) === 1, fails)
    await post(`${TEST}/reset-limits`)

    key = (await post(`${TEST}/generate-key`)).json.key
    check('a generated key is gallopwp_ and 43 letters and digits', /^gallopwp_[0-9A-Za-z]{43}$/.test(key), key?.length)

    const stored = await state()
    const text = JSON.stringify(stored)
    const record = stored.gallop_api_key_hash?.[0]
    check('only a hash is stored, never the key', !text.includes(key) && /^sha256:[0-9a-f]{64}$/.test(record?.hash), record && Object.keys(record))
    check('the stored record keeps the last four characters', record?.last4 === key.slice(-4) && record?.id === 'default')

    const forbidden = await post(COMMENTS, { post: posts.open, ...comment() }, keyed(key))
    check('right key, permission not granted: 403 gallop_key_forbidden', forbidden.status === 403 && forbidden.json.code === 'gallop_key_forbidden', forbidden.json)

    await option('gallop_api_key_permissions', { default: ['made_up'] })
    const madeUp = await post(COMMENTS, { post: posts.open, ...comment() }, keyed(key))
    check('a capability that does not exist grants nothing', madeUp.status === 403, madeUp.json)

    await option('gallop_api_key_permissions', { default: ['comments'] })
  }

  const send = (fields, postId = posts.open, headers = {}) =>
    post(COMMENTS, { post: postId, ...comment(fields) }, { ...keyed(key), ...headers })

  console.log('\nSubmitting')
  let firstBody
  {
    firstBody = { post: posts.open, ...comment({ content: 'Hello from a visitor.' }) }
    const res = await post(COMMENTS, firstBody, {
      ...keyed(key),
      'X-Forwarded-For': '10.9.8.7',
      'CF-Connecting-IP': '10.9.8.7',
      Referer: 'https://server.example/not-the-visitor',
    })
    check('right key with permission: 201', res.status === 201, res.json)
    check('the comment is approved and counted', res.json?.comment?.status === 'approved' && res.json?.count === 6, res.json)
    check('the response has no email address or IP', !res.text.includes(firstBody.authorEmail) && !res.text.includes(firstBody.ip))
    check('the response is never cached', /no-cache|no-store/.test(res.headers.get('cache-control') || ''), res.headers.get('cache-control'))

    const saved = await raw(res.json.comment.id)
    check('stored address is the visitor\'s', saved.ip === firstBody.ip, saved.ip)
    check('stored browser is the visitor\'s', saved.agent === firstBody.userAgent, saved.agent)
    check('stored as logged out, under the name given', saved.userId === 0 && saved.author === firstBody.authorName && saved.email === firstBody.authorEmail, saved)

    const seen = (await get(`${TEST}/server`)).json
    check('other plugins never see the key', !seen.headers.some((h) => /GALLOP/i.test(h)), seen.headers)
    check('other plugins never see the front end\'s forwarding headers', !seen.headers.some((h) => /FORWARDED|CF_CONNECTING|REAL_IP|CLIENT_IP/.test(h)), seen.headers)
    check('other plugins see the visitor\'s address, browser and page', seen.remoteAddr === firstBody.ip && seen.userAgent === firstBody.userAgent && seen.referer === firstBody.referer, seen)
    check('other plugins see nobody logged in', seen.currentUser === 0, seen.currentUser)

    const listed = await get(`${COMMENTS}?post=${posts.open}`)
    check('the new comment is listed, last', listed.json.comments.at(-1).id === res.json.comment.id)

    const odd = 'Mozilla/5.0 "quoted" back\\slash \'single\''
    const oddRes = await send({ userAgent: odd })
    check('a browser string with quotes and a backslash is stored as sent', (await raw(oddRes.json.comment.id)).agent === odd)

    const dirty = await send({ userAgent: `Mozilla\u0000/5.0\r\n${'x'.repeat(400)}` })
    const dirtySaved = (await raw(dirty.json.comment.id)).agent
    check('control characters are removed and the length capped', !/[\u0000-\u001f]/.test(dirtySaved) && dirtySaved.length <= 254, dirtySaved.length)

    const v6 = await send({ ip: '2001:db8::1' })
    check('an IPv6 address is accepted', v6.status === 201 && (await raw(v6.json.comment.id)).ip === '2001:db8::1', v6.json)

    const badReferer = await send({ referer: 'javascript:alert(1)' })
    check('a referer that is not a web address is dropped', badReferer.status === 201 && (await get(`${TEST}/server`)).json.referer === null)

    const onPage = await send({}, posts.page)
    check('a page accepts comments too', onPage.status === 201, onPage.json)
  }

  console.log('\nWordPress\'s own rules')
  {
    const duplicate = await post(COMMENTS, firstBody, keyed(key))
    check('the same comment again: 409 gallop_comment_duplicate', duplicate.status === 409 && duplicate.json.code === 'gallop_comment_duplicate', duplicate.json)
    check('a refusal names WordPress\'s own reason', duplicate.json?.data?.reason === 'comment_duplicate', duplicate.json?.data)

    const a = await send({ ip: '198.51.100.77', authorEmail: 'flood-a@example.com' })
    const b = await send({ ip: '198.51.100.77', authorEmail: 'flood-b@example.com' })
    check('a second comment from one address within seconds: 429 gallop_comment_flood', a.status === 201 && b.status === 429 && b.json.code === 'gallop_comment_flood', [a.status, b.json])

    const other = await send({})
    check('another visitor is not held up by it', other.status === 201, other.json)

    await clearMail()
    await option('comment_moderation', '1')
    const held = await send({ content: 'Please hold this one.' })
    check('moderation on: saved as hold', held.status === 201 && held.json.comment.status === 'hold', held.json)
    check('a held comment is not counted or listed', held.json.count === (await get(`${COMMENTS}?post=${posts.open}`)).json.count && !(await get(`${COMMENTS}?post=${posts.open}`)).json.comments.some((c) => c.id === held.json.comment.id))
    let sent = await mail()
    check('the moderator is emailed about a held comment', sent.length === 1 && /moderat/i.test(sent[0].subject), sent.map((m) => m.subject))
    await option('comment_moderation', '0')

    await clearMail()
    const announced = await send({ ip: '203.0.113.250' })
    sent = await mail()
    check('the post author is emailed about an approved comment', announced.status === 201 && sent.length === 1 && /comment/i.test(sent[0].subject), sent.map((m) => m.subject))
    check('that email names the visitor\'s address', sent[0]?.message.includes('203.0.113.250'))

    await clearMail()
    await option('comments_notify', '0')
    await option('moderation_notify', '0')
    await send({})
    check('notifications switched off: no email', (await mail()).length === 0)
    await option('comments_notify', '1')
    await option('moderation_notify', '1')

    const expect = async (name, fields, status, code, postId) => {
      const r = await send(fields, postId)
      check(name, r.status === status && r.json?.code === code, r.json)
    }

    await expect('no name: 400 gallop_comment_name_email_required', { authorName: '' }, 400, 'gallop_comment_name_email_required')
    await expect('no email: 400 gallop_comment_name_email_required', { authorEmail: '' }, 400, 'gallop_comment_name_email_required')
    await expect('invalid email: 400 gallop_comment_invalid_email', { authorEmail: 'nope' }, 400, 'gallop_comment_invalid_email')
    await expect('blank comment: 400 gallop_comment_empty', { content: '   ' }, 400, 'gallop_comment_empty')
    await expect('very long comment: 400 gallop_comment_too_long', { content: 'x'.repeat(70000) }, 400, 'gallop_comment_too_long')
    await expect('very long name: 400 gallop_comment_name_too_long', { authorName: 'n'.repeat(300) }, 400, 'gallop_comment_name_too_long')
    await expect('invalid visitor address: 400 gallop_comment_invalid_ip', { ip: 'not-an-ip' }, 400, 'gallop_comment_invalid_ip')
    await expect('closed post: 403 gallop_comment_closed', {}, 403, 'gallop_comment_closed', posts.closed)
    await expect('draft post: 404', {}, 404, 'gallop_comments_post_not_found', posts.draft)
    await expect('protected post: 404', {}, 404, 'gallop_comments_post_not_found', posts.protected)
    await expect('missing post: 404', {}, 404, 'gallop_comments_post_not_found', 99999999)

    await option('require_name_email', '0')
    const anonymous = await send({ authorName: '', authorEmail: '' })
    check('name and email optional when the site says so', anonymous.status === 201, anonymous.json)
    await expect('an email that is given must still be valid', { authorEmail: 'nope' }, 400, 'gallop_comment_invalid_email')
    await option('require_name_email', '1')

    await option('close_comments_for_old_posts', '1')
    await expect('post older than the closing age: 403 gallop_comment_closed', {}, 403, 'gallop_comment_closed', posts.old)
    await option('close_comments_for_old_posts', '0')

    await option('comment_registration', '1')
    await expect('site requires login to comment: 403 gallop_comment_login_required', {}, 403, 'gallop_comment_login_required')
    await option('comment_registration', '0')

    const markup = await send({ content: '<script>alert(1)</script><b>bold</b> <a href="https://x.test" onclick="steal()">link</a> <img src=x onerror=alert(1)>' })
    const stored = (await raw(markup.json.comment.id)).content
    check('WordPress strips script, handlers and images on save', markup.status === 201 && !/<script|onclick|onerror|<img/i.test(stored) && /<b>bold<\/b>/.test(stored), stored)
    check('links in comments are marked nofollow ugc', /rel="[^"]*nofollow[^"]*ugc/.test(stored), stored)

    await option('disallowed_keys', 'forbiddenword')
    const banned = await send({ content: 'This has a forbiddenword in it.' })
    check('a disallowed word: saved as trash or spam, never listed', banned.status === 201 && ['trash', 'spam'].includes(banned.json.comment.status), banned.json)
    await option('disallowed_keys', '')

    await option('moderation_keys', 'suspicious')
    const flagged = await send({ content: 'This looks suspicious to the site.' })
    check('a moderation word: saved as hold', flagged.status === 201 && flagged.json.comment.status === 'hold', flagged.json)
    await option('moderation_keys', '')
  }

  console.log('\nReplies')
  {
    const reply = await send({ parent: comments.second })
    check('a reply is saved under its parent', reply.status === 201 && reply.json.comment.parent === comments.second && (await raw(reply.json.comment.id)).parent === comments.second, reply.json)

    const refuse = async (name, parent, code) => {
      const r = await send({ parent })
      check(name, r.status === 400 && r.json?.code === code, r.json)
    }
    await refuse('reply to a missing comment: refused', 99999999, 'gallop_comment_invalid_parent')
    await refuse('reply to a comment awaiting approval: refused', comments.held, 'gallop_comment_invalid_parent')
    await refuse('reply to spam: refused', comments.spam, 'gallop_comment_invalid_parent')
    await refuse('reply to a pingback: refused', comments.pingback, 'gallop_comment_invalid_parent')
    await refuse('reply to a comment on another post: refused', comments.elsewhere, 'gallop_comment_invalid_parent')

    // first (1) > reply (2) > nested (3) > fourth (4) > fifth (5), and no further.
    const fourth = await send({ parent: comments.nested })
    const fifth = await send({ parent: fourth.json.comment.id })
    check('replies nest as deep as the site allows', fourth.status === 201 && fifth.status === 201, [fourth.json, fifth.json])
    await refuse('and no deeper', fifth.json.comment.id, 'gallop_comment_thread_too_deep')

    await option('thread_comments', '0')
    await refuse('threading off: replies refused', comments.second, 'gallop_comment_threading_disabled')
    const flat = await send({})
    check('threading off: comments still accepted', flat.status === 201, flat.json)
    await option('thread_comments', '1')
  }

  console.log('\nA request that arrives logged in')
  {
    const login = (await post(`${TEST}/app-password`)).json
    const basic = 'Basic ' + Buffer.from(`${login.user}:${login.password}`).toString('base64')

    const me = await get(`${API}/wp/v2/users/me`, { headers: { Authorization: basic } })
    check('(the login itself works)', me.status === 200 && me.json?.slug === 'admin', me.json)

    const body = { post: posts.open, ...comment({ content: 'A <video src="x"></video> tag a visitor may not use.' }) }
    const res = await post(COMMENTS, body, { ...keyed(key), Authorization: basic })
    const saved = res.json?.comment ? await raw(res.json.comment.id) : null
    check('the comment is still saved as the visitor, not the user', res.status === 201 && saved.userId === 0 && saved.author === body.authorName, saved)
    check('and its HTML is filtered as a visitor\'s', !/<video/.test(saved?.content ?? '<video'), saved?.content)

    const again = await post(COMMENTS, { post: posts.open, ...comment({ ip: body.ip, authorEmail: 'someone-else@example.com' }) }, { ...keyed(key), Authorization: basic })
    check('and the flood check still applies', again.status === 429, again.json)
  }

  console.log('\nWrong keys')
  {
    await post(`${TEST}/reset-limits`)
    const statuses = []
    for (let i = 1; i <= 11; i++) {
      const r = await post(COMMENTS, { post: posts.open, ...comment() }, keyed(wrongKey(i)))
      statuses.push(`${r.status} ${r.json?.code}`)
    }
    check('ten wrong keys are refused one by one', statuses.slice(0, 10).every((s) => s === '401 gallop_key_invalid'), statuses)
    check('the eleventh is told to stop: 429 gallop_key_rate_limited', statuses[10] === '429 gallop_key_rate_limited', statuses[10])

    const right = await send({})
    check('the right key still works from the same address', right.status === 201, right.json)

    const malformed = await post(COMMENTS, { post: posts.open, ...comment() }, keyed('not-a-gallop-key'))
    check('a malformed key is refused like any wrong key', [401, 429].includes(malformed.status), malformed.json)
    await post(`${TEST}/reset-limits`)
  }

  console.log('\nRegenerating')
  {
    const next = (await post(`${TEST}/generate-key`)).json.key
    check('the new key differs', next !== key)

    const old = await post(COMMENTS, { post: posts.open, ...comment() }, keyed(key))
    check('the old key stops working at once', old.status === 401 && old.json.code === 'gallop_key_invalid', old.json)

    key = next
    const fresh = await send({})
    check('the new key works, with its permissions kept', fresh.status === 201, fresh.json)

    const stored = await state()
    check('one key is stored, not two', stored.gallop_api_key_hash.length === 1, stored.gallop_api_key_hash.length)
    await post(`${TEST}/reset-limits`)
  }

  console.log('\nWhat was there before')
  {
    const routes = Object.keys((await get(`${API}/gallop/v1`)).json.routes)
    for (const route of ['/gallop/v1/post', '/gallop/v1/posts', '/gallop/v1/posts/list', '/gallop/v1/category', '/gallop/v1/auth/login', '/gallop/v1/auth/logout', '/gallop/v1/auth/session']) {
      check(`${route} is still registered`, routes.includes(route))
    }

    const single = await get(`${API}/gallop/v1/post?id=${posts.open}`)
    check('a post still answers, with its comment fields', single.status === 200 && single.json.post.ID === posts.open && single.json.post.commentStatus === 'open', single.json?.post && Object.keys(single.json.post))

    const session = await get(`${API}/gallop/v1/auth/session`)
    check('the session route still answers', session.status === 200 && session.json.user === null, session.json)

    const attempts = []
    for (let i = 0; i < 6; i++) {
      const r = await post(`${API}/gallop/v1/auth/login`, { username: 'admin', password: `wrong-${i}` })
      attempts.push(`${r.status} ${r.json?.code}`)
    }
    check('login is still limited to five wrong passwords', attempts.slice(0, 5).every((s) => s === '401 gallop_auth_invalid_credentials') && attempts[5] === '429 gallop_auth_rate_limited', attempts)
  }

  console.log('\nUninstalling')
  {
    const before = Object.keys(await state())
    check('(there is something to remove)', before.includes('gallop_api_key_hash') && before.some((n) => n.startsWith('_transient_gallop_auth_')), before)

    const kept = (await get(`${COMMENTS}?post=${posts.open}`)).json.count
    await post(`${TEST}/uninstall`)
    const after = Object.keys(await state())
    check('every option and transient is gone', after.length === 0, after)
    check('comments are left alone', (await get(`${COMMENTS}?post=${posts.open}`)).json.count === kept)
  }
}

// The server was started with GALLOP_WP_API_KEY defined as KEY.
async function constant() {
  console.log(`\nGallop checks against ${BASE}: key set in wp-config.php\n`)

  const fromConfig = process.env.KEY
  if (!fromConfig) throw new Error('Set KEY to the value the server was started with.')

  const { posts } = (await post(`${TEST}/seed`)).json
  await post(`${TEST}/reset-limits`)

  const generated = (await post(`${TEST}/generate-key`)).json.key
  await option('gallop_api_key_permissions', { default: ['comments'] })

  const viaGenerated = await post(COMMENTS, { post: posts.open, ...comment() }, keyed(generated))
  check('a generated key is ignored while the constant is defined', viaGenerated.status === 401 && viaGenerated.json.code === 'gallop_key_invalid', viaGenerated.json)

  const viaConstant = await post(COMMENTS, { post: posts.open, ...comment() }, keyed(fromConfig))
  check('the key from wp-config.php is accepted', viaConstant.status === 201, viaConstant.json)

  await option('gallop_api_key_permissions', { default: [] })
  const ungranted = await post(COMMENTS, { post: posts.open, ...comment() }, keyed(fromConfig))
  check('permissions apply to it all the same', ungranted.status === 403 && ungranted.json.code === 'gallop_key_forbidden', ungranted.json)
}

// The server was started with GALLOP_WP_API_KEY defined as something unusable.
async function constantInvalid() {
  console.log(`\nGallop checks against ${BASE}: unusable key in wp-config.php\n`)

  const { posts } = (await post(`${TEST}/seed`)).json
  await post(`${TEST}/reset-limits`)

  const generated = (await post(`${TEST}/generate-key`)).json.key
  await option('gallop_api_key_permissions', { default: ['comments'] })

  for (const [name, value] of [['the generated key', generated], ['the constant\'s own value', process.env.KEY || 'short'], ['a well-formed wrong key', wrongKey()]]) {
    const r = await post(COMMENTS, { post: posts.open, ...comment() }, keyed(value))
    check(`${name} is refused`, r.status === 401 && r.json.code === 'gallop_key_invalid', r.json)
  }

  check('reading is unaffected', (await get(`${COMMENTS}?post=${posts.open}`)).status === 200)
}

// The server was started without WP_ENVIRONMENT_TYPE set to local, over plain HTTP.
async function notLocal() {
  console.log(`\nGallop checks against ${BASE}: plain HTTP, not a local environment\n`)

  const { posts } = (await post(`${TEST}/seed`)).json
  await post(`${TEST}/reset-limits`)

  const key = (await post(`${TEST}/generate-key`)).json.key
  await option('gallop_api_key_permissions', { default: ['comments'] })

  const r = await post(COMMENTS, { post: posts.open, ...comment() }, keyed(key))
  check('the right key over plain HTTP: 403 gallop_https_required', r.status === 403 && r.json.code === 'gallop_https_required', r.json)

  const none = await post(COMMENTS, { post: posts.open, ...comment() })
  check('no key is still 401, whatever the connection', none.status === 401 && none.json.code === 'gallop_key_missing', none.json)

  check('reading is unaffected', (await get(`${COMMENTS}?post=${posts.open}`)).status === 200)
}

const runs = { main, constant, 'constant-invalid': constantInvalid, 'not-local': notLocal }

try {
  if (!runs[MODE]) throw new Error(`Unknown MODE "${MODE}".`)
  await runs[MODE]()
} catch (error) {
  failures.push(`stopped: ${error.message}`)
  console.error(`\n  STOPPED  ${error.stack || error}`)
}

console.log(`\n${passed} passed, ${failures.length} failed`)
for (const name of failures) console.log(`  - ${name}`)
process.exit(failures.length ? 1 : 0)
