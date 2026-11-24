from app.extractors import extract, sitemap_locs

HTML = """<html><head><title> Hello  World </title>
<meta name="description" content="Desc"><meta name="robots" content="noindex">
<link rel="canonical" href="/canon"></head>
<body><h1>One</h1><h1>Two</h1><h2>Sub</h2>
<a href="/about">About us</a><a href="mailto:x@y.z">Mail</a><a href="https://ext.com/p" rel="nofollow">Ext</a>
<img src="/a.png" alt="A"><img src="/b.png"><img src="/c.png" alt="">
<script>ignore me please</script><p>Some body text here.</p></body></html>"""


def test_extracts_metadata():
    data = extract(HTML, "https://site.com/page")
    assert data["title"] == "Hello World"
    assert data["meta_description"] == "Desc"
    assert data["robots_meta"] == "noindex"
    assert data["canonical"] == "https://site.com/canon"
    assert data["h1"] == ["One", "Two"]
    assert [link["url"] for link in data["links"]] == ["https://site.com/about", "https://ext.com/p"]
    assert data["links"][1]["rel"] == "nofollow"
    assert [i["alt"] for i in data["images"]] == ["A", None, ""]
    assert "ignore me" not in data["text_excerpt"]


def test_malformed_html_does_not_crash():
    data = extract("<html><title>x<body><h1>unclosed<a href='/q'>", "https://s.com/")
    assert data["title"]


def test_sitemap_parsing():
    pages, children = sitemap_locs("""<?xml version="1.0"?><urlset><url><loc>https://s.com/a</loc></url></urlset>""")
    assert pages == ["https://s.com/a"] and children == []
    pages, children = sitemap_locs("""<sitemapindex><sitemap><loc>https://s.com/sm1.xml</loc></sitemap></sitemapindex>""")
    assert children == ["https://s.com/sm1.xml"]
