// Regression coverage for keeping example voting entirely separate from real voters.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../e-vote-final-enhanced/js/voting_tutorial_preview.js'), 'utf8');
const screens = ['categories', 'answer', 'proof', 'next-award', 'list', 'review', 'summary', 'cast', 'confirm', 'voted', 'qr-business', 'qr-vote', 'qr-confirm', 'qr-voted', 'qr-main'];

function example(screen) {
  let realStorageReads = 0;
  let realNetworkCalls = 0;
  const context = {
    VOTE_GUIDE_DEMO: {
      screen, token: 'example', categoryId: 10, eventId: 1, choice_name: 'Example Cafe',
      categories: [{ id: 10, name: 'Food' }], labels: {},
      questions: [1, 2].map(question_id => ({ question_id, category_id: 10, question_name: `Award ${question_id}`, answer_fields: 'business_photo', choices: [{ choice_id: 900000001, choice_name: 'Example Cafe' }] })),
    },
    location: { href: 'http://example.test/voting_tutorial_preview.php', origin: 'http://example.test' },
    history: { replaceState() {} },
    document: { addEventListener() {} },
    parent: { postMessage() {} },
    addEventListener() {},
    URL, Response, setTimeout, clearTimeout,
    fetch: () => { realNetworkCalls++; throw new Error('Live network reached'); },
  };
  Object.defineProperty(context, 'localStorage', { configurable: true, get() { realStorageReads++; throw new Error('Real voter storage read'); } });
  Object.defineProperty(context, 'sessionStorage', { configurable: true, get() { realStorageReads++; throw new Error('Real session storage read'); } });
  context.window = context;
  vm.createContext(context);
  vm.runInContext(source, context);
  return { context, counts: () => ({ realStorageReads, realNetworkCalls }) };
}

(async () => {
  for (const screen of screens) {
    const { context, counts } = example(screen);
    const session = await (await context.fetch('check_voter_session.php')).json();
    assert.equal(session.voter_id, 900000000);
    assert.equal(context.localStorage.getItem('voter_id'), '900000000');
    const result = await (await context.fetch('submit_vote.php', { body: JSON.stringify({ answers: [{ question_id: 1, choice_id: 900000001 }] }) })).json();
    assert.equal(result.status, 'success');
    assert.equal(result.writes, 1);
    const finalized = await (await context.fetch('get_finalized_answers.php')).json();
    assert.ok(finalized.finalized.some(answer => answer.question_id === 1));
    await assert.rejects(context.fetch('google_voter_login.php'), /cannot call a live endpoint/);
    await assert.rejects(context.fetch('upload_vote_proof.php'), /cannot call a live endpoint/);
    await assert.rejects(context.fetch('unknown_endpoint.php'), /cannot call a live endpoint/);
    assert.deepEqual(counts(), { realStorageReads: 0, realNetworkCalls: 0 });
  }
  const first = example('answer').context;
  first.localStorage.setItem('test-draft', 'first guide');
  assert.equal(example('answer').context.localStorage.getItem('test-draft'), null);
  const php = fs.readFileSync(path.join(__dirname, '../e-vote-final-enhanced/voting_tutorial_preview.php'), 'utf8');
  assert.match(php, /connect-src 'none'/);
  assert.match(php, /MYSQLI_TRANS_START_READ_ONLY/);
  for (const partial of ['voter_category_content', 'voter_ballot_content', 'voter_summary_content', 'voter_qr_ballot_content', 'voter_confirm_modal']) assert.ok(php.includes(`/partials/${partial}.php`));
  console.log(`${screens.length} tutorial screens: no real storage reads or network submissions; fresh progress on replay.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
