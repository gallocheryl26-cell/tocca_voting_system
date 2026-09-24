/**
 * Voter field labels — dropdown + optional proof, or open text for song titles.
 * Mirrors PHP category_voting_profile_labels.
 */

export const VOTING_PROFILE_LABELS = {
  business: {
    profile: 'business',
    uses_open_text: false,
    show_proof: true,
    list_instruction: 'Pick from the list. A photo is optional.',
    open_label: 'Your answer',
    open_placeholder: 'Type your answer',
    proof_label: 'Proof of purchase (optional)',
    proof_hint: 'Add a photo if you have one. You can still vote without it.',
    proof_add_label: 'Add photo',
    validation_message: 'Please select a business from the list.',
    validation_message_switch: 'Please select a business from the list before changing categories.',
  },
  media: {
    profile: 'media',
    uses_open_text: true,
    show_proof: false,
    list_instruction: 'Type the song title and singer.',
    open_label: 'Song title',
    open_placeholder: 'Type the song title',
    open_label_2: 'Singer',
    open_placeholder_2: 'Type the singer',
    proof_label: '',
    proof_hint: '',
    proof_add_label: '',
    validation_message: 'Please enter the song title and singer.',
    validation_message_switch: 'Please enter the song title and singer before changing categories.',
  },
  places: {
    profile: 'places',
    uses_open_text: false,
    show_proof: true,
    list_instruction: 'Pick from the list. A photo is optional.',
    open_label: 'Your answer',
    open_placeholder: 'Type your answer',
    proof_label: 'Proof of purchase (optional)',
    proof_hint: 'Add a photo if you have one. You can still vote without it.',
    proof_add_label: 'Add photo',
    validation_message: 'Please select a place from the list.',
    validation_message_switch: 'Please select a place from the list before changing categories.',
  },
  general: {
    profile: 'general',
    uses_open_text: false,
    show_proof: true,
    list_instruction: 'Pick from the list. A photo is optional.',
    open_label: 'Your answer',
    open_placeholder: 'Type your answer',
    proof_label: 'Proof of purchase (optional)',
    proof_hint: 'Add a photo if you have one. You can still vote without it.',
    proof_add_label: 'Add photo',
    validation_message: 'Please select your pick from the list.',
    validation_message_switch: 'Please select your pick from the list before changing categories.',
  },
  mixed: {
    profile: 'mixed',
    uses_open_text: false,
    show_proof: true,
    list_instruction: 'Pick from the list. A photo is optional.',
    open_label: 'Your answer',
    open_placeholder: 'Type your answer',
    proof_label: 'Proof of purchase (optional)',
    proof_hint: 'Add a photo if you have one. You can still vote without it.',
    proof_add_label: 'Add photo',
    validation_message: 'Please select your choice from the list.',
    validation_message_switch: 'Please select your choice from the list before changing categories.',
  },
};

/** Mirror of PHP award_answer_fields_is_artist_award */
export function looksLikeArtistAward(awardName = '') {
  const n = String(awardName).trim().toLowerCase();
  if (!n) return false;
  return ['make-up artist', 'makeup artist', 'make up artist', 'hairstylist', 'hair stylist']
    .some((phrase) => n.includes(phrase));
}

/** Mirror of PHP award_answer_fields_is_place_award */
export function looksLikePlaceAward(awardName = '') {
  const n = String(awardName).trim().toLowerCase();
  if (!n) return false;
  return ['date place', 'hangout spot', 'viewing spot', 'tourist spot']
    .some((phrase) => n.includes(phrase));
}

function productBusinessCopy(awardName = '') {
  if (looksLikePlaceAward(awardName)) {
    return {
      open_label: 'Name of Place',
      open_placeholder: 'Type the name of the place',
      open_label_2: '',
      open_placeholder_2: '',
      list_instruction: 'Type the name of the place.',
      validation_message: 'Please enter the name of the place.',
      validation_message_switch: 'Please enter the name of the place before changing categories.',
      show_proof: false,
      proof_label: '',
      proof_hint: '',
      proof_add_label: '',
      single_field: true,
    };
  }
  if (looksLikeArtistAward(awardName)) {
    return {
      open_label: 'Hairstylist / Artist Name',
      open_placeholder: 'Type the hairstylist / artist name',
      open_label_2: 'Business Name',
      open_placeholder_2: 'Type the business name',
      list_instruction: 'Type the hairstylist / artist name and the business name.',
      validation_message: 'Please enter the hairstylist / artist name and the business name.',
      validation_message_switch: 'Please enter the hairstylist / artist name and the business name before changing categories.',
      show_proof: false,
      proof_label: '',
      proof_hint: '',
      proof_add_label: '',
      single_field: false,
    };
  }
  return {
    open_label: 'Product Name',
    open_placeholder: 'Type the product name',
    open_label_2: 'Business Name',
    open_placeholder_2: 'Type the business name',
    list_instruction: 'Type the product and the business name.',
    validation_message: 'Please enter the product name and the business name.',
    validation_message_switch: 'Please enter the product name and the business name before changing categories.',
    single_field: false,
  };
}

/** Mirror of PHP category_voting_profile_infer_from_award_name */
export function inferProfileFromAwardTitle(awardName = '') {
  const n = String(awardName).trim().toLowerCase();
  if (!n) return 'general';

  const phrases = {
    media: ['break up song', 'breakup song', 'love song', 'theme song', 'music video'],
    places: ['date place', 'hangout spot', 'viewing spot', 'tourist spot'],
    business: ['food establishment', 'business establishment', 'food service', 'make-up artist', 'makeup artist'],
  };
  for (const [profile, list] of Object.entries(phrases)) {
    if (list.some((phrase) => n.includes(phrase))) return profile;
  }

  const words = {
    media: ['song', 'music', 'anthem', 'opm', 'band', 'album', 'tune', 'lyric', 'singer', 'dj'],
    places: ['place', 'venue', 'location', 'park', 'beach', 'resort', 'spot', 'hangout', 'destination', 'view'],
    business: [
      'business', 'establishment', 'store', 'shop', 'salon', 'spa', 'restaurant', 'cafe', 'coffee',
      'hotel', 'bar', 'mall', 'brand', 'company', 'service', 'provider', 'clinic', 'gym',
    ],
  };
  for (const profile of ['media', 'places', 'business']) {
    if (words[profile].some((word) => n.includes(word))) return profile;
  }
  return 'general';
}

export function awardAnswerFields(question, state) {
  const raw = String(question?.answer_fields || question?.field_labels?.answer_fields || '').toLowerCase();
  if (raw === 'song_singer' || raw === 'product_business' || raw === 'business_photo' || raw === 'meryenda') {
    return raw;
  }
  if (String(question?.question_name || '').toLowerCase().includes('meryenda')) {
    return 'meryenda';
  }
  if (looksLikePlaceAward(question?.question_name || '')) {
    return 'business_photo';
  }
  if (looksLikeArtistAward(question?.question_name || '')) {
    return 'product_business';
  }
  if (question?.answer_mode === 'open_text' || Number(question?.choice_type) === 0) {
    return 'song_singer';
  }
  if (inferProfileFromAwardTitle(question?.question_name || '') === 'media') {
    return 'song_singer';
  }
  const profile = String(state?.currentVotingProfile || question?.field_labels?.profile || '').toLowerCase();
  if (profile === 'media') return 'song_singer';
  if (profile === 'mixed') return 'product_business';
  return 'business_photo';
}

export function awardUsesOpenText(question, state) {
  const fields = awardAnswerFields(question, state);
  return fields === 'song_singer';
}

export const MERYENDA_OTHER = '__other__';

export function awardUsesMeryenda(question, state) {
  return awardAnswerFields(question, state) === 'meryenda';
}

export function awardUsesProductField(question, state) {
  // Product/artist/stylist are registration-fed ballot dropdowns (not typed product + business).
  return false;
}

export function fieldLabelsForAward(categoryProfile, awardName = '', choiceType = 1, answerFields = '') {
  const fields = String(answerFields || '').toLowerCase();
  if (fields === 'song_singer' || fields === 'product_business' || fields === 'business_photo' || fields === 'meryenda') {
    if (fields === 'meryenda') {
      return {
        ...resolveFieldLabels('general'),
        uses_open_text: false,
        uses_product: false,
        show_proof: false,
        answer_fields: 'meryenda',
        single_field: false,
        list_instruction: 'Pick the product, then type the vendor name and location. If it is not on the list, choose Not on the list and type the product.',
        open_label: 'Product',
        open_placeholder: 'Type the product',
        open_label_2: 'Vendor name / location',
        open_placeholder_2: 'Type the vendor name and location',
        validation_message: 'Please choose the product and enter the vendor name and location.',
        validation_message_switch: 'Please choose the product and enter the vendor name and location before changing categories.',
        category_profile: String(categoryProfile || 'general').toLowerCase(),
      };
    }
    if (fields === 'song_singer') {
      return {
        ...resolveFieldLabels('media'),
        uses_open_text: true,
        uses_product: false,
        show_proof: false,
        answer_fields: fields,
        single_field: false,
        category_profile: String(categoryProfile || 'business').toLowerCase(),
      };
    }
    const base = resolveFieldLabels(categoryProfile || 'business');
    if (fields === 'product_business') {
      const isArtist = looksLikeArtistAward(awardName);
      const isStylist = /event stylist|stylist/i.test(String(awardName || '')) && !/hair/i.test(String(awardName || ''));
      return {
        ...resolveFieldLabels(categoryProfile || 'business'),
        uses_open_text: false,
        uses_product: false,
        uses_ballot_entries: true,
        show_proof: !isArtist && !isStylist && !looksLikePlaceAward(awardName),
        answer_fields: fields,
        list_instruction: isArtist
          ? 'Pick the business and make-up artist from the list.'
          : (isStylist
            ? 'Pick the business and stylist from the list.'
            : 'Pick the business and product from the list. Proof of purchase is optional.'),
        validation_message: isArtist
          ? 'Please select a business - artist from the list.'
          : (isStylist
            ? 'Please select a business - stylist from the list.'
            : 'Please select a business - product from the list.'),
        validation_message_switch: isArtist
          ? 'Please select a business - artist from the list before changing categories.'
          : (isStylist
            ? 'Please select a business - stylist from the list before changing categories.'
            : 'Please select a business - product from the list before changing categories.'),
        single_field: true,
        category_profile: String(categoryProfile || 'business').toLowerCase(),
      };
    }
    return { ...base, uses_product: false, answer_fields: fields };
  }
  const profile = String(categoryProfile || 'business').toLowerCase();
  const type = choiceType == null || choiceType === '' ? 1 : Number(choiceType);
  const openText =
    type === 0 ||
    inferProfileFromAwardTitle(awardName) === 'media' ||
    profile === 'media';

  if (openText) {
    const inferred = inferProfileFromAwardTitle(awardName);
    return {
      ...resolveFieldLabels(inferred === 'media' ? 'media' : 'general'),
      uses_open_text: true,
      show_proof: false,
      category_profile: profile,
      inferred_profile: inferred,
    };
  }

  if (profile === 'mixed') {
    const inferred = inferProfileFromAwardTitle(awardName);
    return {
      ...resolveFieldLabels(inferred),
      category_profile: 'mixed',
      inferred_profile: inferred,
    };
  }
  return resolveFieldLabels(profile);
}

export function resolveFieldLabels(profileOrLabels) {
  if (
    profileOrLabels &&
    typeof profileOrLabels === 'object' &&
    (profileOrLabels.list_instruction || profileOrLabels.uses_open_text || profileOrLabels.proof_label)
  ) {
    return profileOrLabels;
  }
  const key = String(profileOrLabels || 'business').toLowerCase();
  return VOTING_PROFILE_LABELS[key] || VOTING_PROFILE_LABELS.business;
}

export function getActiveFieldLabels(state) {
  if (state?.currentFieldLabels?.list_instruction || state?.currentFieldLabels?.proof_label) {
    return state.currentFieldLabels;
  }
  return resolveFieldLabels(state?.currentVotingProfile || 'business');
}

/** Per-award labels (mixed category, song titles) or category defaults. */
export function getLabelsForQuestion(question, state) {
  let labels;
  if (question?.field_labels?.answer_fields || question?.field_labels?.uses_product || question?.field_labels?.uses_open_text || question?.field_labels?.list_instruction) {
    labels = question.field_labels;
  } else {
    const fields = awardAnswerFields(question, state);
    labels = fieldLabelsForAward(
      state?.currentVotingProfile || 'business',
      question?.question_name || '',
      question?.choice_type,
      fields
    );
  }
  const name = question?.question_name || '';
  if (looksLikeArtistAward(name) || looksLikePlaceAward(name)) {
    return { ...labels, ...productBusinessCopy(name) };
  }
  return labels;
}

const OPEN_TEXT_SEP = ' — ';

export function titleCaseOpenTextPart(s = '') {
  const t = String(s || '').trim().replace(/\s+/g, ' ');
  if (!t) return '';
  return t.toLowerCase().replace(/(^|[^\p{L}\p{N}])(\p{L})/gu, (_, prefix, letter) => prefix + letter.toUpperCase());
}

export function parseOpenTextPair(raw = '') {
  const s = String(raw || '').trim();
  if (!s) {
    return { title: '', singer: '' };
  }
  const dash = s.indexOf(OPEN_TEXT_SEP);
  if (dash !== -1) {
    return {
      title: s.slice(0, dash).trim(),
      singer: s.slice(dash + OPEN_TEXT_SEP.length).trim(),
    };
  }
  const hyphen = s.match(/^(.*?)\s+[-–—]\s+(.+)$/);
  if (hyphen) {
    return { title: hyphen[1].trim(), singer: hyphen[2].trim() };
  }
  const by = s.match(/^(.*?)\s+by\s+(.+)$/i);
  if (by) {
    return { title: by[1].trim(), singer: by[2].trim() };
  }
  return { title: s, singer: '' };
}

export function formatOpenTextPair(title = '', singer = '') {
  const t = titleCaseOpenTextPart(title);
  const n = titleCaseOpenTextPart(singer);
  if (!t && !n) return '';
  if (!n) return t;
  if (!t) return n;
  return `${t}${OPEN_TEXT_SEP}${n}`;
}

/** True when this award uses one typed answer (e.g. Best Date Place). */
export function usesSingleOpenField(source = {}) {
  if (!source || typeof source !== 'object') return false;
  if (source.single_field === true || source.field_labels?.single_field === true) return true;
  if (source.single_field === false) return false;
  const labels = source.field_labels && typeof source.field_labels === 'object' ? source.field_labels : source;
  if (labels.single_field === true) return true;
  if (Object.prototype.hasOwnProperty.call(labels, 'open_label_2') && String(labels.open_label_2).trim() === '') {
    return true;
  }
  return looksLikePlaceAward(source.question_name || labels.question_name || '');
}

export function isCompleteOpenTextPair(raw = '') {
  const { title, singer } = parseOpenTextPair(raw);
  return title !== '' && singer !== '';
}

export function isCompleteOpenTextAnswer(raw = '', singleField = false) {
  const text = String(raw || '').trim();
  if (!text) return false;
  if (singleField) return true;
  return isCompleteOpenTextPair(text);
}

/** Positive select / ballot-entry / business id, or 0 when missing. */
export function numericChoiceId(saved = {}) {
  const raw = saved?.choice_id ?? saved?.selectedOption ?? saved?.ballot_entry_id;
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : 0;
}

export function listedAnswerLabel(saved = {}) {
  return usableChoiceDisplayName(
    saved?.choice_text || saved?.choiceText || saved?.selected_answer_text || ''
  );
}

/** Dropdown prompt text such as "Choose a business - product…". Not a vote. */
export function isBallotPlaceholderLabel(value = '') {
  const raw = String(value ?? '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/[….]+/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();
  if (!raw) return false;
  return /^(choose|select|pick)\s+(a|an|your)\s+/.test(raw);
}

export function usableChoiceDisplayName(value = '') {
  const clean = plainChoiceDisplayName(value);
  if (!clean || isBallotPlaceholderLabel(clean)) return '';
  return clean;
}

function answerFieldsOf(saved = {}, question = {}) {
  const raw = String(
    saved.answer_fields ||
    question.answer_fields ||
    question.field_labels?.answer_fields ||
    ''
  ).toLowerCase();
  if (raw === 'song_singer' || raw === 'product_business' || raw === 'business_photo' || raw === 'meryenda') {
    return raw;
  }
  const name = saved.question_name || question.question_name || '';
  if (String(name).toLowerCase().includes('meryenda')) return 'meryenda';
  if (looksLikeArtistAward(name)) return 'product_business';
  if (inferProfileFromAwardTitle(name) === 'media') return 'song_singer';
  return 'business_photo';
}

function normalizeMatchText(value = '') {
  return plainChoiceDisplayName(value)
    .toLowerCase()
    .replace(/\([^)]*\)/g, ' ')
    .replace(/[—–−]/g, '-')
    .replace(/[^a-z0-9]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function matchLabelParts(value = '') {
  const parsed = parseOpenTextPair(value);
  const parts = [parsed.title, parsed.singer].map(normalizeMatchText).filter(Boolean);
  if (parts.length) return parts;
  const n = normalizeMatchText(value);
  return n ? [n] : [];
}

/**
 * Map a stored draft id (ballot_entry_id or business choice_id) or display
 * label back onto the current dropdown option.
 */
export function matchNamedChoice(choices, storedId, label = '') {
  const list = Array.isArray(choices) ? choices : [];
  const sid = String(storedId ?? '').trim();
  if (sid && sid !== '0') {
    const bySelectId = list.find((c) => String(c.choice_id) === sid);
    if (bySelectId) return bySelectId;
    const byBiz = list.find(
      (c) =>
        String(c.business_choice_id || '') === sid ||
        String(c.ballot_entry_id || '') === sid
    );
    if (byBiz) return byBiz;
  }
  const needle = normalizeMatchText(label);
  if (!needle || isBallotPlaceholderLabel(label)) return null;
  const exact = list.find((c) => normalizeMatchText(c.choice_name) === needle);
  if (exact) return exact;
  const storedParts = matchLabelParts(label);
  const ranked = list
    .map((choice) => {
      const optionText = normalizeMatchText(choice.choice_name);
      const optionParts = matchLabelParts(choice.choice_name);
      let score = 0;
      if (optionText && (needle.includes(optionText) || optionText.includes(needle))) score += 20;
      if (storedParts.length && storedParts.every((part) => optionText.includes(part))) score += 40;
      if (optionParts.length && optionParts.every((part) => needle.includes(part))) score += 40;
      return { choice, score };
    })
    .filter((row) => row.score > 0)
    .sort((a, b) => b.score - a.score);
  return ranked[0]?.choice || null;
}

/**
 * Same readiness rule for Summary ("Ready to cast") and Cast / Vote All.
 * List awards (including named artist / product picks) are complete when a
 * choice_id or a visible pick label is stored. Songs still need title + singer.
 */
export function selectionIsReady(saved = {}, question = {}) {
  if (!saved || typeof saved !== 'object') return false;
  const fields = answerFieldsOf(saved, question);
  if (fields === 'meryenda') {
    const typed = String(saved.freetext || saved.manual_input || '').trim();
    if (numericChoiceId(saved) > 0) return typed !== '';
    return isCompleteOpenTextPair(typed);
  }
  if (numericChoiceId(saved) > 0) return true;
  const typed = usableChoiceDisplayName(saved.freetext || saved.manual_input || '');
  const label = listedAnswerLabel(saved);
  const singleField = usesSingleOpenField({
    ...saved,
    question_name: saved.question_name || question.question_name || '',
    field_labels: question.field_labels,
  });
  if (fields === 'song_singer') {
    return isCompleteOpenTextAnswer(typed || label, singleField);
  }
  if (label) return true;
  if (!typed) return false;
  if (fields === 'product_business') {
    return isCompleteOpenTextAnswer(typed, singleField);
  }
  return Boolean(typed);
}

/**
 * Choices.js stores logo markup in option.text. Summary and drafts need the
 * plain business / product name only.
 */
export function plainChoiceDisplayName(value = '') {
  const raw = String(value ?? '').trim();
  if (!raw) return '';
  if (!/[<>]/.test(raw)) {
    return raw.replace(/\s+/g, ' ').trim();
  }
  try {
    const doc = new DOMParser().parseFromString(raw, 'text/html');
    const named = doc.querySelector('.choice-option-name');
    const fromName = named ? String(named.textContent || '').trim() : '';
    if (fromName) return fromName.replace(/\s+/g, ' ').trim();
    return String(doc.body?.textContent || '').replace(/\s+/g, ' ').trim();
  } catch (e) {
    return raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  }
}

export function sanitizeStoredAnswerMap(map) {
  if (!map || typeof map !== 'object' || Array.isArray(map)) return map;
  Object.keys(map).forEach((key) => {
    const cat = map[key];
    const selections = Array.isArray(cat?.selections) ? cat.selections : [];
    selections.forEach((sel) => {
      if (!sel || typeof sel !== 'object') return;
      if (sel.choice_text) sel.choice_text = usableChoiceDisplayName(sel.choice_text);
      if (sel.selected_answer_text) sel.selected_answer_text = usableChoiceDisplayName(sel.selected_answer_text);
      if (sel.freetext) sel.freetext = isBallotPlaceholderLabel(sel.freetext) ? '' : plainChoiceDisplayName(sel.freetext);
      if (sel.manual_input) sel.manual_input = isBallotPlaceholderLabel(sel.manual_input) ? '' : plainChoiceDisplayName(sel.manual_input);
    });
  });
  return map;
}

