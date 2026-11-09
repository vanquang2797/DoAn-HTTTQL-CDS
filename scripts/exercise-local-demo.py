"""Exercise the named synthetic local demo through HTTP; never use real child data."""
import json
import urllib.request
import urllib.error
from pathlib import Path

BASE = 'http://127.0.0.1:8000/api/'
token = None
results = []

def call(method, path, data=None, expected=200):
    request = urllib.request.Request(BASE + path,
        data=json.dumps(data).encode() if data is not None else None,
        headers={'Accept': 'application/json', 'Content-Type': 'application/json',
                 **({'Authorization': 'Bearer ' + token} if token else {})}, method=method)
    try:
        response = urllib.request.urlopen(request, timeout=25)
    except urllib.error.HTTPError as error:
        response = error
    body = json.loads(response.read())
    assert response.status == expected, (path, response.status, body.get('message'))
    return body.get('data')

try:
    token = call('POST', 'auth/login', {'email': 'babi.owner@example.test', 'password': 'BabiLocalTest2026!'})['token']
    child = next(c for c in call('GET', 'child-profiles') if c['name'] == 'Bé Kiểm Thử')
    child_id = child['id']
    def revision():
        return call('GET', f'child-profiles/{child_id}')['data_revision']

    existing = call('GET', f'child-profiles/{child_id}/health-records?per_page=100')['data']
    dates = {r['measured_at'][:10] for r in existing}
    for date, height, weight in [('2026-07-20', 70.0, 8.0), ('2026-08-20', 71.5, 8.3), ('2026-09-20', 73.0, 8.6)]:
        if date not in dates:
            call('POST', 'health-records', {'child_profile_id': child_id, 'measured_at': date,
                'height_cm': height, 'weight_kg': weight, 'measurement_method': 'lying',
                'notes': 'AUTOTEST: số liệu giả lập để kiểm thử, không phải dữ liệu y tế thực.',
                'expected_data_revision': revision()}, 201)
    assert len(call('GET', f'child-profiles/{child_id}/health-records?per_page=100')['data']) >= 3
    results.append('PASS: three synthetic growth measurements persisted and readable')
    schedules = call('GET', f'child-profiles/{child_id}/vaccinations?per_page=100')['data']
    schedule = next(s for s in schedules if s['status'] != 'recorded')
    sid = schedule['id']
    call('PUT', f'vaccinations/{sid}/appointment', {'appointment_date': '2026-10-28',
        'appointment_source': 'AUTOTEST: lịch hẹn giả lập', 'expected_data_revision': revision()})
    call('PATCH', f'vaccinations/{sid}/mark-pending', {'confirmed': True, 'expected_data_revision': revision()})
    record = call('POST', f'child-profiles/{child_id}/vaccination-records', {
        'vaccine_name': schedule['name'], 'dose_number': schedule['dose_number'],
        'date_precision': 'exact', 'administered_date': '2026-10-20',
        'vaccination_schedule_id': sid, 'source': 'parent_report',
        'facility': 'AUTOTEST - cơ sở giả lập', 'notes': 'AUTOTEST: kiểm thử, không phải lần tiêm thực.',
        'expected_data_revision': revision()}, 201)
    state = next(s for s in call('GET', f'child-profiles/{child_id}/vaccinations')['data'] if s['id'] == sid)
    assert state['status'] == 'recorded'
    results.append('PASS: appointment, explicit pending, exact vaccination and recorded schedule')
    call('POST', f'vaccination-records/{record["id"]}/void', {'reason': 'AUTOTEST: kết thúc thử nghiệm lần tiêm giả lập',
        'expected_version': record['version'], 'expected_data_revision': revision()})
    history = call('GET', f'vaccination-records/{record["id"]}/changes')
    assert len(history) == 2 and history[-1]['action'] == 'void'
    state = next(s for s in call('GET', f'child-profiles/{child_id}/vaccinations')['data'] if s['id'] == sid)
    assert state['status'] == 'unrecorded'
    results.append('PASS: void retains audit and resets vaccination schedule')
    owner_token = token
    space_id = child['family_space_id']
    viewer = call('POST', 'auth/login', {'email': 'babi.viewer@example.test', 'password': 'BabiLocalTest2026!'})
    viewer_token = viewer['token']
    viewer_id = viewer['user']['id']
    members = call('GET', f'family-spaces/{space_id}/members')
    if not any(m['user_id'] == viewer_id for m in members):
        link = call('POST', f'family-spaces/{space_id}/join-links', {}, 201)
        token = viewer_token
        request = call('POST', 'family-space-join-requests', {'token': link['token']}, 202)
        token = owner_token
        call('POST', f'family-join-requests/{request["id"]}/approve', {})
        call('DELETE', f'family-join-links/{link["id"]}')
        results.append('PASS: live join request, owner approval, join-link revocation')
    else:
        results.append('PASS: demo viewer membership already exists')
    token = viewer_token
    assert call('GET', f'child-profiles/{child_id}')['id'] == child_id
    call('POST', 'health-records', {'child_profile_id': child_id, 'measured_at': '2026-10-21',
        'height_cm': 73, 'weight_kg': 8.6, 'measurement_method': 'lying',
        'expected_data_revision': revision()}, 403)
    results.append('PASS: approved viewer reads child data but cannot write health measurements')
    call('POST', 'auth/logout')
    token = owner_token
finally:
    if token:
        call('POST', 'auth/logout')
    output = Path(__file__).resolve().parents[1] / 'tmp/local-demo-check.json'
    output.write_text(json.dumps(results, indent=2), encoding='utf-8')
    print('\n'.join(results))
