import pytest

from app.safety import UnsafeUrlError, is_public_ip, normalize_url, validate_url


def public(_host):
    return ["93.184.216.34"]


@pytest.mark.parametrize("url", [
    "file:///etc/passwd", "ftp://example.com/", "gopher://example.com/", "http://localhost/",
    "http://127.0.0.1/", "http://10.0.0.5/", "http://192.168.1.1/", "http://169.254.169.254/latest/meta-data/",
    "http://[::1]/", "http://intranet/", "http://db.internal/", "http://user:pass@example.com/",
])
def test_rejects_unsafe_urls(url):
    with pytest.raises(UnsafeUrlError):
        validate_url(url, public)


def test_rejects_host_resolving_to_private_ip():
    with pytest.raises(UnsafeUrlError):
        validate_url("https://rebind.example.com/", lambda h: ["10.1.2.3"])


def test_rejects_mixed_public_and_private_resolution():
    with pytest.raises(UnsafeUrlError):
        validate_url("https://mixed.example.com/", lambda h: ["93.184.216.34", "127.0.0.1"])


def test_accepts_public_url_and_normalizes():
    assert validate_url("HTTPS://Example.com:443//a//b?x=1#frag", public) == "https://example.com/a/b?x=1"


@pytest.mark.parametrize("ip,ok", [
    ("8.8.8.8", True), ("10.0.0.1", False), ("100.64.1.1", False), ("::ffff:127.0.0.1", False),
    ("fe80::1", False), ("224.0.0.1", False), ("2606:4700:4700::1111", True),
])
def test_is_public_ip(ip, ok):
    assert is_public_ip(ip) is ok


def test_normalize_relative():
    assert normalize_url("../x", "https://a.com/b/c/") == "https://a.com/b/x"
